<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AuthController;
use App\Domain\Identity\Role;
use App\Http\ProblemDetailsFactory;
use App\Tests\Support\AuthContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP-контракт ендпоїнтів автентифікації (AUTH-40) і формат помилок
 * RFC 7807 з розширеннями `code` і `requestId` (RBAC-33).
 */
#[CoversClass(AuthController::class)]
#[CoversClass(ProblemDetailsFactory::class)]
final class AuthControllerTest extends TestCase
{
    private const string PASSWORD = 'Rampa!Staff2026';

    private AuthContext $context;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->context = new AuthContext();
        $this->controller = new AuthController(
            authentication: $this->context->authentication,
            tokens: $this->context->tokens,
            problems: new ProblemDetailsFactory(),
            clock: $this->context->clock,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(string $path, array $body, array $headers = []): Request
    {
        $server = ['HTTP_X-Request-Id' => 'req-test-1'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.$name] = $value;
        }

        return Request::create(
            uri: $path,
            method: 'POST',
            server: $server,
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * AUTH-40: обидва staff-префікси обслуговує identity-staff-service.
     *
     * @return array<string, array{string}>
     */
    public static function staffPrefixProvider(): array
    {
        return [
            'admin-web' => ['/api/admin/v1/auth/login'],
            'store-web' => ['/api/store/v1/auth/login'],
        ];
    }

    #[DataProvider('staffPrefixProvider')]
    public function testLoginReturnsTokenPairAndProfile(string $path): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $response = $this->controller->login($this->request($path, [
            'email' => 'Manager@Silpo.UA',
            'password' => self::PASSWORD,
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->json($response);
        self::assertSame('Bearer', $body['tokenType']);
        self::assertSame(900, $body['expiresIn']);
        self::assertNotSame('', $body['accessToken']);
        self::assertNotSame('', $body['refreshToken']);
        self::assertSame('store_manager', $body['user']['role']);
        self::assertSame(['A'], $body['user']['scope']['storeIds']);
        self::assertSame('req-test-1', $response->headers->get('X-Request-Id'));

        // Пароль ніколи не потрапляє у відповідь (AUTH-61)
        self::assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());
    }

    /**
     * RBAC-33 / таблиця 3.7: 401 AUTH_INVALID_CREDENTIALS у форматі problem+json.
     */
    public function testInvalidCredentialsReturnProblemJson(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $response = $this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => 'Wrong!Pass2026',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = $this->json($response);
        self::assertSame('about:blank', $body['type']);
        self::assertSame('Помилка автентифікації', $body['title']);
        self::assertSame(401, $body['status']);
        self::assertSame('Невірний логін або пароль.', $body['detail']);
        self::assertSame('AUTH_INVALID_CREDENTIALS', $body['code']);
        self::assertSame('req-test-1', $body['requestId']);
        self::assertSame('/api/admin/v1/auth/login', $body['instance']);
    }

    /**
     * AUTH-50: 6-та спроба — 423 AUTH_ACCOUNT_LOCKED.
     */
    public function testAccountLockAfterFiveFailedAttempts(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        for ($i = 0; $i < 5; ++$i) {
            $this->controller->login($this->request('/api/admin/v1/auth/login', [
                'email' => 'manager@silpo.ua',
                'password' => 'Wrong!Pass2026',
            ]));
        }

        $response = $this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => self::PASSWORD,
        ]));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('AUTH_ACCOUNT_LOCKED', $this->json($response)['code']);
    }

    /**
     * AUTH-12: деактивований акаунт — 403 AUTH_ACCOUNT_DISABLED.
     */
    public function testDisabledAccountReturns403(): void
    {
        $this->context->createUser('fired@silpo.ua', Role::Analyst, [], self::PASSWORD, active: false);

        $response = $this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'fired@silpo.ua',
            'password' => self::PASSWORD,
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('AUTH_ACCOUNT_DISABLED', $this->json($response)['code']);
    }

    public function testMissingFieldsReturn422(): void
    {
        $response = $this->controller->login($this->request('/api/admin/v1/auth/login', ['email' => 'a@silpo.ua']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->json($response)['code']);
    }

    /**
     * AUTH-31: refresh ротує пару; повторне використання — 401 AUTH_REFRESH_REUSED.
     */
    public function testRefreshRotatesAndDetectsReuse(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $login = $this->json($this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => self::PASSWORD,
        ])));

        $refreshed = $this->controller->refresh($this->request('/api/admin/v1/auth/refresh', [
            'refreshToken' => $login['refreshToken'],
        ]));
        self::assertSame(200, $refreshed->getStatusCode());
        self::assertNotSame($login['refreshToken'], $this->json($refreshed)['refreshToken']);

        $reused = $this->controller->refresh($this->request('/api/admin/v1/auth/refresh', [
            'refreshToken' => $login['refreshToken'],
        ]));

        self::assertSame(401, $reused->getStatusCode());
        self::assertSame('AUTH_REFRESH_REUSED', $this->json($reused)['code']);
        self::assertSame(
            'З міркувань безпеки всі сесії завершено. Увійдіть повторно.',
            $this->json($reused)['detail'],
        );
    }

    public function testLogoutReturns204AndKillsSession(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $login = $this->json($this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => self::PASSWORD,
        ])));

        $response = $this->controller->logout($this->request('/api/admin/v1/auth/logout', [
            'refreshToken' => $login['refreshToken'],
        ]));

        self::assertSame(204, $response->getStatusCode());

        $afterLogout = $this->controller->refresh($this->request('/api/admin/v1/auth/refresh', [
            'refreshToken' => $login['refreshToken'],
        ]));
        self::assertSame(401, $afterLogout->getStatusCode());
    }

    /**
     * AUTH-14: зміна пароля з валідним access-токеном.
     */
    public function testChangePasswordWithValidAccessToken(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $login = $this->json($this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => self::PASSWORD,
        ])));

        $response = $this->controller->changePassword($this->request(
            '/api/admin/v1/auth/password',
            ['currentPassword' => self::PASSWORD, 'newPassword' => 'Nova!Parolya2026'],
            ['Authorization' => 'Bearer '.$login['accessToken']],
        ));

        self::assertSame(204, $response->getStatusCode());

        $relogin = $this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => 'Nova!Parolya2026',
        ]));
        self::assertSame(200, $relogin->getStatusCode());
    }

    /**
     * AUTH-13: слабкий новий пароль — 422 AUTH_WEAK_PASSWORD з переліком правил.
     */
    public function testWeakNewPasswordReturns422WithViolations(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $login = $this->json($this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => self::PASSWORD,
        ])));

        $response = $this->controller->changePassword($this->request(
            '/api/admin/v1/auth/password',
            ['currentPassword' => self::PASSWORD, 'newPassword' => 'qwerty'],
            ['Authorization' => 'Bearer '.$login['accessToken']],
        ));

        self::assertSame(422, $response->getStatusCode());

        $body = $this->json($response);
        self::assertSame('AUTH_WEAK_PASSWORD', $body['code']);
        self::assertIsArray($body['violations']);
        self::assertNotEmpty($body['violations']);
    }

    /**
     * AUTH-02 / RBAC-AC-02: токен ЧУЖОГО (partner) контуру на staff-маршруті —
     * 401 AUTH_TOKEN_INVALID.
     */
    public function testPartnerTokenOnStaffRouteIsRejected(): void
    {
        $response = $this->controller->changePassword($this->request(
            '/api/admin/v1/auth/password',
            ['currentPassword' => self::PASSWORD, 'newPassword' => 'Nova!Parolya2026'],
            ['Authorization' => 'Bearer '.$this->context->partnerAccessToken()],
        ));

        self::assertSame(401, $response->getStatusCode());

        $body = $this->json($response);
        self::assertSame('AUTH_TOKEN_INVALID', $body['code']);
        self::assertSame('Помилка автентифікації. Увійдіть повторно.', $body['detail']);
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $response = $this->controller->changePassword($this->request(
            '/api/admin/v1/auth/password',
            ['currentPassword' => self::PASSWORD, 'newPassword' => 'Nova!Parolya2026'],
        ));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTH_TOKEN_INVALID', $this->json($response)['code']);
    }

    /**
     * AUTH-17: логін акаунта з 2FA повертає 401 AUTH_2FA_REQUIRED
     * і короткоживучий challenge-токен.
     */
    public function testTwoFactorChallengeIsReturnedInProblemBody(): void
    {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $user = $this->context->createUser('root@silpo.ua', Role::SuperAdmin, [], self::PASSWORD);
        $user->enableTwoFactor($secret, $this->context->clock->now());
        $this->context->users->save($user);

        $response = $this->controller->login($this->request('/api/admin/v1/auth/login', [
            'email' => 'root@silpo.ua',
            'password' => self::PASSWORD,
        ]));

        self::assertSame(401, $response->getStatusCode());

        $body = $this->json($response);
        self::assertSame('AUTH_2FA_REQUIRED', $body['code']);
        self::assertArrayHasKey('challengeToken', $body);

        $second = $this->controller->login($this->request('/api/admin/v1/auth/login', [
            'challengeToken' => $body['challengeToken'],
            'totpCode' => $this->context->totp->codeAt($secret, $this->context->clock->now()),
        ]));

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('super_admin', $this->json($second)['user']['role']);
    }

    public function testMalformedJsonBodyReturns422(): void
    {
        $request = Request::create(
            uri: '/api/admin/v1/auth/login',
            method: 'POST',
            content: '{не json}',
        );

        $response = $this->controller->login($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->json($response)['code']);
        self::assertNotSame('', (string) $this->json($response)['requestId']);
    }
}
