<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InternalAuthController;
use App\Domain\Identity\Contour;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Http\BearerToken;
use App\Http\ProblemDetailsFactory;
use App\Tests\Support\AuthContext;
use App\Tests\Support\CountingStaffUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Контракт `/internal/v1/auth/verify` для api-gateway (nginx `auth_request`).
 *
 * Успіх — 204 і рівно пʼять заголовків ідентичності; невдача — 401
 * `application/problem+json` з `code` = AUTH_TOKEN_INVALID, БЕЗ жодного
 * заголовка ідентичності (інакше шлюз пропустив би запит з порожньою
 * або чужою ідентичністю).
 */
#[CoversClass(InternalAuthController::class)]
#[CoversClass(BearerToken::class)]
final class InternalAuthControllerTest extends TestCase
{
    private AuthContext $context;
    private CountingStaffUserRepository $users;
    private InternalAuthController $controller;

    protected function setUp(): void
    {
        $this->context = new AuthContext();
        $this->users = new CountingStaffUserRepository($this->context->users);

        $this->controller = new InternalAuthController(
            tokens: $this->context->tokens,
            users: $this->users,
            problems: new ProblemDetailsFactory(),
        );
    }

    private function request(?string $authorization): Request
    {
        $server = ['HTTP_X-Request-Id' => 'req-verify-1'];

        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        return Request::create('/internal/v1/auth/verify', 'GET', server: $server);
    }

    private function verify(?string $accessToken): Response
    {
        return $this->controller->verify(
            $this->request(null === $accessToken ? null : 'Bearer '.$accessToken),
        );
    }

    private function accessTokenFor(StaffUser $user): string
    {
        return $this->context->tokens->issueFor($user)->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Кожна невдача виглядає ОДНАКОВО: 401, AUTH_TOKEN_INVALID і жодного
     * заголовка ідентичності.
     */
    private function assertRejected(Response $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('AUTH_TOKEN_INVALID', $this->problem($response)['code']);

        foreach ([
            InternalAuthController::USER_HEADER,
            InternalAuthController::ROLE_HEADER,
            InternalAuthController::SUPPLIER_HEADER,
            InternalAuthController::STORE_IDS_HEADER,
            InternalAuthController::CONTOUR_HEADER,
        ] as $header) {
            self::assertFalse(
                $response->headers->has($header),
                \sprintf('Відповідь-відмова не має містити заголовок %s.', $header),
            );
        }
    }

    /**
     * Ролі staff-контуру (RBAC-06) і очікуваний `X-Store-Ids`:
     * RBAC-16 — у network-ролей перелік порожній навіть тоді, коли storeIds
     * у документі заповнені, бо їхній скоуп визначає роль, а не список.
     *
     * @return array<string, array{Role, list<string>, string}>
     */
    public static function staffRoleProvider(): array
    {
        return [
            'super_admin' => [Role::SuperAdmin, [], ''],
            'network_manager' => [Role::NetworkManager, ['S-01'], ''],
            'store_manager' => [Role::StoreManager, ['S-01', 'S-02'], 'S-01,S-02'],
            'store_operator' => [Role::StoreOperator, ['S-07'], 'S-07'],
            'analyst' => [Role::Analyst, [], ''],
        ];
    }

    /**
     * @param list<string> $storeIds
     */
    #[DataProvider('staffRoleProvider')]
    public function testValidTokenReturnsIdentityHeaders(Role $role, array $storeIds, string $expectedStoreHeader): void
    {
        $user = $this->context->createUser($role->value.'@silpo.ua', $role, $storeIds);

        $response = $this->verify($this->accessTokenFor($user));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());

        // Кожен заголовок контракту перевіряється поіменно
        self::assertSame($user->id(), $response->headers->get(InternalAuthController::USER_HEADER));
        self::assertSame($role->value, $response->headers->get(InternalAuthController::ROLE_HEADER));
        self::assertSame('', $response->headers->get(InternalAuthController::SUPPLIER_HEADER));
        self::assertSame($expectedStoreHeader, $response->headers->get(InternalAuthController::STORE_IDS_HEADER));
        self::assertSame(Contour::Staff->value, $response->headers->get(InternalAuthController::CONTOUR_HEADER));

        // Заголовок присутній навіть коли порожній — шлюзу є що підставити
        self::assertTrue($response->headers->has(InternalAuthController::SUPPLIER_HEADER));
        self::assertTrue($response->headers->has(InternalAuthController::STORE_IDS_HEADER));

        self::assertSame('req-verify-1', $response->headers->get('X-Request-Id'));
    }

    /**
     * AUTH-30: access-токен живе 15 хвилин; на 16-й — відмова.
     */
    public function testExpiredTokenIsRejected(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['S-01']);
        $token = $this->accessTokenFor($user);

        $this->context->clock->advance('+16 minutes');

        // Власний код сервісу AUTH_TOKEN_EXPIRED назовні не просочується
        $this->assertRejected($this->verify($token));
    }

    /**
     * Зіпсований підпис: жодна маніпуляція з токеном не проходить.
     *
     * @return array<string, array{\Closure(string, AuthContext): string}>
     */
    public static function brokenSignatureProvider(): array
    {
        return [
            // Змінюється ПЕРШИЙ символ підпису: у base64url останній символ
            // 32-байтового HS256-підпису несе лише 2 значущі біти, тож його
            // підміна в трьох випадках із чотирьох дала б ті самі байти.
            'змінений перший символ підпису' => [
                static function (string $token): string {
                    [$header, $payload, $signature] = explode('.', $token);

                    return $header.'.'.$payload.'.'
                        .('A' === $signature[0] ? 'B' : 'A').substr($signature, 1);
                },
            ],
            'підпис відрізано' => [
                static fn (string $token): string => substr($token, 0, (int) strrpos($token, '.') + 1),
            ],
            'підмінений payload при старому підписі' => [
                static function (string $token): string {
                    [$header, $payload, $signature] = explode('.', $token);
                    /** @var array<string, mixed> $claims */
                    $claims = json_decode(
                        (string) base64_decode(strtr($payload, '-_', '+/'), true),
                        true,
                        512,
                        \JSON_THROW_ON_ERROR,
                    );
                    $claims['role'] = Role::SuperAdmin->value;

                    $forged = rtrim(strtr(base64_encode(
                        json_encode($claims, \JSON_THROW_ON_ERROR),
                    ), '+/', '-_'), '=');

                    return $header.'.'.$forged.'.'.$signature;
                },
            ],
            'не JWT узагалі' => [
                static fn (): string => 'zovsim-ne-token',
            ],
        ];
    }

    /**
     * @param \Closure(string): string $tamper
     */
    #[DataProvider('brokenSignatureProvider')]
    public function testTamperedTokenIsRejected(\Closure $tamper): void
    {
        $user = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['S-07']);

        $this->assertRejected($this->verify($tamper($this->accessTokenFor($user))));
    }

    /**
     * AUTH-02: токен ЧУЖОГО контуру — підписаний ключем partner-контуру.
     */
    public function testPartnerContourTokenIsRejected(): void
    {
        $this->assertRejected($this->verify($this->context->partnerAccessToken()));
    }

    /**
     * AUTH-03: ізоляція контурів тримається не лише на підписі — токен
     * із partner-клеймами, підписаний ПРАВИЛЬНИМ staff-ключем, теж 401.
     */
    public function testPartnerClaimsSignedByStaffKeyAreRejected(): void
    {
        $this->assertRejected($this->verify($this->context->partnerClaimsSignedByStaffKey()));
    }

    /**
     * Захист у глибину: `contour: staff`, валідні iss/aud і staff-підпис,
     * але роль — з partner-контуру.
     */
    public function testStaffContourTokenWithPartnerRoleIsRejected(): void
    {
        $now = $this->context->clock->now();

        $token = $this->context->staffSigner->sign([
            'sub' => 'smuggled-1',
            'role' => Role::Driver->value,
            'contour' => Contour::Staff->value,
            'scope' => ['storeIds' => []],
            'sid' => 'sid-smuggled',
            'jti' => 'jti-smuggled',
            'typ' => 'access',
            'iss' => Contour::Staff->issuer(),
            'aud' => Contour::Staff->audience(),
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + 900,
        ]);

        $this->assertRejected($this->verify($token));
    }

    /**
     * AUTH-28: відкликаний токен — `jti` у Redis-denylist.
     */
    public function testRevokedTokenIsRejected(): void
    {
        $user = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $issued = $this->context->tokens->issueFor($user);

        // До відкликання токен валідний
        self::assertSame(204, $this->verify($issued->accessToken)->getStatusCode());

        $this->context->denylist->revoke($issued->accessJti, $issued->accessExpiresAt);

        $this->assertRejected($this->verify($issued->accessToken));
    }

    /**
     * AUTH-12/RBAC-26: деактивований акаунт не проходить перевірку навіть із
     * технічно валідним, ще не протермінованим токеном.
     */
    public function testDeactivatedAccountIsRejected(): void
    {
        $user = $this->context->createUser('fired@silpo.ua', Role::Analyst);
        $token = $this->accessTokenFor($user);

        self::assertSame(204, $this->verify($token)->getStatusCode());

        $user->deactivate($this->context->clock->now());
        $this->context->users->save($user);

        $this->assertRejected($this->verify($token));
    }

    /**
     * DATA-03: архівований (soft-deleted) запис так само неактивний.
     */
    public function testArchivedAccountIsRejected(): void
    {
        $user = $this->context->createUser('archived@silpo.ua', Role::StoreManager, ['S-01']);
        $token = $this->accessTokenFor($user);

        $user->archive($this->context->clock->now());
        $this->context->users->save($user);

        $this->assertRejected($this->verify($token));
    }

    /**
     * Токен валідний, але користувача в базі вже немає.
     */
    public function testUnknownSubjectIsRejected(): void
    {
        $user = $this->context->createUser('ghost@silpo.ua', Role::StoreOperator, ['S-07']);
        $token = $this->accessTokenFor($user);

        $this->context->users->clear();

        $this->assertRejected($this->verify($token));
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $this->assertRejected($this->controller->verify($this->request(null)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedAuthorizationProvider(): array
    {
        return [
            'порожній заголовок' => [''],
            'схема Basic' => ['Basic dXNlcjpwYXNz'],
            'без схеми' => ['eyJhbGciOiJIUzI1NiJ9.e30.sig'],
            'схема без токена' => ['Bearer'],
            'схема з порожнім токеном' => ['Bearer   '],
            'схема злита з токеном' => ['BearereyJhbGciOiJIUzI1NiJ9'],
            'чужа схема' => ['Token eyJhbGciOiJIUzI1NiJ9.e30.sig'],
        ];
    }

    #[DataProvider('malformedAuthorizationProvider')]
    public function testMalformedAuthorizationHeaderIsRejected(string $authorization): void
    {
        $this->assertRejected($this->controller->verify($this->request($authorization)));
    }

    /**
     * Refresh-токен не є access-токеном: `typ` перевіряється.
     */
    public function testRefreshTokenIsNotAcceptedAsAccessToken(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['S-01']);

        $this->assertRejected($this->verify($this->context->tokens->issueFor($user)->refreshToken));
    }

    /**
     * RBAC-33: тіло відмови — повний RFC 7807 з `code` і `requestId`.
     */
    public function testFailureBodyIsRfc7807(): void
    {
        $body = $this->problem($this->controller->verify($this->request(null)));

        self::assertSame('about:blank', $body['type']);
        self::assertSame('Помилка автентифікації', $body['title']);
        self::assertSame(401, $body['status']);
        self::assertSame('Помилка автентифікації. Увійдіть повторно.', $body['detail']);
        self::assertSame('AUTH_TOKEN_INVALID', $body['code']);
        self::assertSame('req-verify-1', $body['requestId']);
        self::assertSame('/internal/v1/auth/verify', $body['instance']);
    }

    /**
     * RBAC-26: пониження прав діє негайно — заголовки формуються з поточного
     * стану БД, а не з клеймів уже виданого токена.
     */
    public function testHeadersFollowCurrentDatabaseStateNotStaleClaims(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['S-01', 'S-02']);
        $token = $this->accessTokenFor($user);

        self::assertSame(
            'S-01,S-02',
            $this->verify($token)->headers->get(InternalAuthController::STORE_IDS_HEADER),
        );

        $user->changeRole(Role::StoreOperator, $this->context->clock->now());
        $user->changeScope(['S-09'], $this->context->clock->now());
        $this->context->users->save($user);

        $response = $this->verify($token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('store_operator', $response->headers->get(InternalAuthController::ROLE_HEADER));
        self::assertSame('S-09', $response->headers->get(InternalAuthController::STORE_IDS_HEADER));
    }

    /**
     * Продуктивність: ендпоїнт викликається на КОЖЕН запит до API, тому
     * невалідний токен не має доходити до MongoDB — перевірка підпису,
     * контуру й denylist відсікає його раніше.
     */
    public function testInvalidTokensNeverTouchTheDatabase(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['S-01']);
        $issued = $this->context->tokens->issueFor($user);

        $this->controller->verify($this->request(null));
        $this->controller->verify($this->request('Basic dXNlcjpwYXNz'));
        $this->verify('zovsim-ne-token');
        $this->verify($this->context->partnerAccessToken());
        $this->verify($this->context->partnerClaimsSignedByStaffKey());
        $this->verify($issued->refreshToken);

        self::assertSame(0, $this->users->identityReads);

        // Валідний токен коштує рівно ОДНЕ читання
        self::assertSame(204, $this->verify($issued->accessToken)->getStatusCode());
        self::assertSame(1, $this->users->identityReads);
    }
}
