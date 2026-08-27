<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Auth\AuthenticationService;
use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\LoginResult;
use App\Domain\Auth\TokenService;
use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Shared\Clock;
use App\Domain\Shared\DomainException;
use App\Http\ProblemDetailsFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP-API автентифікації staff-контуру (AUTH-40).
 *
 * Схема URL канонічна: identity-staff-service обслуговує ОБИДВА staff-префікси —
 * `/api/admin/v1/auth/*` (admin-web) та `/api/store/v1/auth/*` (store-web).
 *
 * Усі помилки — RFC 7807 `application/problem+json` з `code` і `requestId` (RBAC-33).
 * Токен partner-контуру на цих маршрутах завершується 401 AUTH_TOKEN_INVALID (AUTH-02).
 */
final readonly class AuthController
{
    public function __construct(
        private AuthenticationService $authentication,
        private TokenService $tokens,
        private ProblemDetailsFactory $problems,
        private Clock $clock,
    ) {
    }

    /**
     * AUTH-10/AUTH-11/AUTH-17: логін email + пароль; для акаунтів з 2FA —
     * другий крок за challenge-токеном і TOTP-кодом.
     */
    #[Route('/api/admin/v1/auth/login', name: 'admin_auth_login', methods: ['POST'])]
    #[Route('/api/store/v1/auth/login', name: 'store_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $payload = $this->payload($request);
            $userAgent = $request->headers->get('User-Agent');
            $ip = $request->getClientIp();

            $challengeToken = $this->stringField($payload, 'challengeToken', required: false);

            $result = '' !== $challengeToken
                ? $this->authentication->completeTwoFactorLogin(
                    $challengeToken,
                    $this->stringField($payload, 'totpCode'),
                    $userAgent,
                    $ip,
                )
                : $this->authentication->login(
                    $this->stringField($payload, 'email'),
                    $this->rawField($payload, 'password'),
                    $userAgent,
                    $ip,
                );

            return $this->tokenResponse($result, $request);
        });
    }

    /**
     * AUTH-31: ротація пари токенів. Повторне використання погашеного refresh
     * гасить увесь ланцюжок сесії — 401 AUTH_REFRESH_REUSED.
     */
    #[Route('/api/admin/v1/auth/refresh', name: 'admin_auth_refresh', methods: ['POST'])]
    #[Route('/api/store/v1/auth/refresh', name: 'store_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $payload = $this->payload($request);

            $result = $this->authentication->refresh(
                $this->stringField($payload, 'refreshToken'),
                $request->headers->get('User-Agent'),
                $request->getClientIp(),
            );

            return $this->tokenResponse($result, $request);
        });
    }

    /**
     * AUTH-32: logout відкликає refresh поточної сесії; `allDevices=true` —
     * усі сесії користувача.
     */
    #[Route('/api/admin/v1/auth/logout', name: 'admin_auth_logout', methods: ['POST'])]
    #[Route('/api/store/v1/auth/logout', name: 'store_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $payload = $this->payload($request);

            $this->authentication->logout(
                $this->stringField($payload, 'refreshToken'),
                (bool) ($payload['allDevices'] ?? false),
            );

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * AUTH-14: зміна пароля вимагає поточного пароля; після зміни всі
     * refresh-токени, крім поточної сесії, відкликаються.
     * Потрібен валідний access-токен staff-контуру в заголовку Authorization.
     */
    #[Route('/api/admin/v1/auth/password', name: 'admin_auth_password', methods: ['POST'])]
    #[Route('/api/store/v1/auth/password', name: 'store_auth_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $claims = $this->tokens->verifyAccessToken($this->bearerToken($request));
            $payload = $this->payload($request);

            $this->authentication->changePassword(
                userId: $claims->subject,
                currentPassword: $this->rawField($payload, 'currentPassword'),
                newPassword: $this->rawField($payload, 'newPassword'),
                currentSessionId: $claims->sessionId,
            );

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    private function tokenResponse(LoginResult $result, Request $request): JsonResponse
    {
        $now = $this->clock->now();

        $response = new JsonResponse([
            'tokenType' => 'Bearer',
            'accessToken' => $result->tokens->accessToken,
            'expiresIn' => $result->tokens->accessTtlSeconds($now),
            'accessExpiresAt' => $result->tokens->accessExpiresAt->format(\DATE_ATOM),
            'refreshToken' => $result->tokens->refreshToken,
            'refreshExpiresAt' => $result->tokens->refreshExpiresAt->format(\DATE_ATOM),
            'sessionId' => $result->tokens->sessionId,
            'user' => $result->profile(),
        ], Response::HTTP_OK);

        $response->headers->set(
            ProblemDetailsFactory::REQUEST_ID_HEADER,
            ProblemDetailsFactory::requestId($request),
        );

        return $response;
    }

    /**
     * Єдина точка перетворення доменних помилок у RFC 7807 (RBAC-33).
     *
     * @param \Closure(): JsonResponse $action
     */
    private function guard(Request $request, \Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $exception) {
            return $this->problems->fromDomainException($exception, $request);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $content = $request->getContent();

        if ('' === trim($content)) {
            return $request->request->all();
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('Тіло запиту має бути коректним JSON.');
        }

        if (!\is_array($decoded)) {
            throw new ValidationException('Тіло запиту має бути JSON-обʼєктом.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringField(array $payload, string $field, bool $required = true): string
    {
        $value = $payload[$field] ?? null;

        if (null === $value || \is_array($value)) {
            if ($required) {
                throw new ValidationException(
                    \sprintf('Поле "%s" обовʼязкове.', $field),
                    [\sprintf('Не заповнено поле "%s"', $field)],
                );
            }

            return '';
        }

        $value = trim((string) $value);

        if ('' === $value && $required) {
            throw new ValidationException(
                \sprintf('Поле "%s" обовʼязкове.', $field),
                [\sprintf('Не заповнено поле "%s"', $field)],
            );
        }

        return $value;
    }

    /**
     * Пароль НЕ обрізається пробілами: пробіл — валідний символ пароля.
     *
     * @param array<string, mixed> $payload
     */
    private function rawField(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!\is_string($value) || '' === $value) {
            throw new ValidationException(
                \sprintf('Поле "%s" обовʼязкове.', $field),
                [\sprintf('Не заповнено поле "%s"', $field)],
            );
        }

        return $value;
    }

    /**
     * AUTH-02: відсутній або чужий токен → 401 AUTH_TOKEN_INVALID.
     */
    private function bearerToken(Request $request): string
    {
        $header = $request->headers->get('Authorization', '');

        if (1 !== preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            throw new InvalidTokenException('відсутній заголовок Authorization: Bearer');
        }

        return trim($matches[1]);
    }
}
