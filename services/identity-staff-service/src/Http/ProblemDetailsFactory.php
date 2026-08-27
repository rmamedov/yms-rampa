<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Shared\DomainException;
use App\Domain\Shared\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Формування тіла помилки за RFC 7807 (RBAC-33).
 *
 * Єдиний формат для всіх сервісів YMS:
 * {"type":"about:blank","title":"...","status":422,"detail":"...",
 *  "code":"...","requestId":"..."}
 *
 * `title`/`detail` — українською, без розкриття внутрішніх деталей (AUTH-53).
 */
final class ProblemDetailsFactory
{
    public const string CONTENT_TYPE = 'application/problem+json';
    public const string REQUEST_ID_HEADER = 'X-Request-Id';

    public function fromDomainException(DomainException $exception, Request $request): JsonResponse
    {
        return $this->create(
            status: $exception->httpStatus(),
            detail: $exception->userMessage(),
            code: $exception->errorCode(),
            request: $request,
            extensions: $exception->context(),
        );
    }

    /**
     * @param array<string, mixed> $extensions
     */
    public function create(
        int $status,
        string $detail,
        string $code,
        Request $request,
        array $extensions = [],
    ): JsonResponse {
        $body = [
            'type' => 'about:blank',
            'title' => self::titleFor($status),
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->getPathInfo(),
            'code' => $code,
            'requestId' => self::requestId($request),
        ];

        foreach ($extensions as $key => $value) {
            if (!\array_key_exists($key, $body)) {
                $body[$key] = $value;
            }
        }

        $response = new JsonResponse($body, $status);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set(self::REQUEST_ID_HEADER, (string) $body['requestId']);

        return $response;
    }

    /**
     * RBAC-32/AUTH-52: requestId звʼязує відмову в доступі з логами та аудитом.
     */
    public static function requestId(Request $request): string
    {
        $header = $request->headers->get(self::REQUEST_ID_HEADER);

        return null !== $header && '' !== trim($header) ? trim($header) : Uuid::v4();
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'Некоректний запит',
            Response::HTTP_UNAUTHORIZED => 'Помилка автентифікації',
            Response::HTTP_FORBIDDEN => 'Доступ заборонено',
            Response::HTTP_NOT_FOUND => 'Ресурс не знайдено',
            Response::HTTP_CONFLICT => 'Конфлікт стану',
            Response::HTTP_GONE => 'Посилання недійсне',
            Response::HTTP_LOCKED => 'Обліковий запис заблоковано',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'Дані не пройшли перевірку',
            Response::HTTP_TOO_MANY_REQUESTS => 'Забагато запитів',
            default => $status >= 500 ? 'Внутрішня помилка сервісу' : 'Помилка запиту',
        };
    }
}
