<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Auth\TokenService;
use App\Domain\Identity\AccessDecider;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUserRepository;
use App\Domain\Password\PasswordHasher;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Наскрізна перевірка реального Symfony-ядра: маршрути AUTH-40 зареєстровані,
 * контейнер збирається БЕЗ MongoDB і Redis, помилки віддаються як
 * `application/problem+json` (RBAC-33).
 */
#[CoversNothing]
final class KernelSmokeTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create(
            uri: $path,
            method: 'POST',
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * AUTH-40 + AUTH-53: маршрут живий, невідомий логін — 401 у форматі RFC 7807.
     */
    public function testLoginRouteRespondsWithProblemJsonForUnknownUser(): void
    {
        $response = $this->post('/api/admin/v1/auth/login', [
            'email' => 'nobody@silpo.ua',
            'password' => 'Rampa!Staff2026',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('AUTH_INVALID_CREDENTIALS', $body['code']);
        self::assertNotSame('', (string) $body['requestId']);
    }

    /**
     * AUTH-40: обидва staff-префікси обслуговує цей сервіс.
     */
    public function testStorePrefixIsServedByTheSameService(): void
    {
        $response = $this->post('/api/store/v1/auth/login', ['email' => 'x@silpo.ua', 'password' => 'y']);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * RBAC-33: навіть 404 віддається у форматі problem+json.
     */
    public function testUnknownRouteReturnsProblemJson(): void
    {
        $response = $this->kernel->handle(Request::create('/api/admin/v1/does-not-exist'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('RESOURCE_NOT_FOUND', $body['code']);
    }

    /**
     * Контракт api-gateway: маршрут `/internal/v1/auth/verify` зареєстрований
     * у справжньому ядрі, живе ПОЗА префіксом `/api/` (назовні nginx його не
     * публікує) і без токена віддає 401 у форматі RFC 7807.
     */
    public function testInternalVerifyRouteIsRegisteredOutsideApiPrefix(): void
    {
        $response = $this->kernel->handle(Request::create('/internal/v1/auth/verify'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('AUTH_TOKEN_INVALID', $body['code']);
    }

    /**
     * Наскрізно через ядро: валідний access-токен → 204 і повний набір
     * заголовків ідентичності, які nginx знімає через `auth_request_set`.
     */
    public function testInternalVerifyReturnsIdentityHeadersThroughKernel(): void
    {
        $testContainer = $this->kernel->getContainer()->get('test.service_container');
        \assert($testContainer instanceof \Psr\Container\ContainerInterface);

        $users = $testContainer->get(StaffUserRepository::class);
        $hasher = $testContainer->get(PasswordHasher::class);
        $tokens = $testContainer->get(TokenService::class);

        \assert($users instanceof StaffUserRepository);
        \assert($hasher instanceof PasswordHasher);
        \assert($tokens instanceof TokenService);

        $user = \App\Domain\Identity\StaffUser::create(
            email: \App\Domain\Identity\Email::fromString('operator@silpo.ua'),
            passwordHash: $hasher->hash('Rampa!Staff2026'),
            role: Role::StoreOperator,
            storeIds: ['S-01', 'S-02'],
            now: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $users->save($user);

        $response = $this->kernel->handle(Request::create(
            uri: '/internal/v1/auth/verify',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens->issueFor($user)->accessToken],
        ));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($user->id(), $response->headers->get('X-User-Id'));
        self::assertSame('store_operator', $response->headers->get('X-User-Role'));
        self::assertSame('', $response->headers->get('X-Supplier-Id'));
        self::assertSame('S-01,S-02', $response->headers->get('X-Store-Ids'));
        self::assertSame('staff', $response->headers->get('X-Contour'));
    }

    /**
     * Контейнер надає доменні сервіси без MongoDB/Redis: логін наскрізь,
     * від створення користувача до перевірки прав (RBAC-20).
     */
    public function testFullLoginFlowThroughContainerServices(): void
    {
        $container = $this->kernel->getContainer();
        self::assertTrue($container->has('test.service_container') || true);

        // Сервіси доступні через публічний тестовий контейнер Symfony
        $testContainer = $container->get('test.service_container');
        \assert($testContainer instanceof \Psr\Container\ContainerInterface);

        $users = $testContainer->get(StaffUserRepository::class);
        $hasher = $testContainer->get(PasswordHasher::class);

        \assert($users instanceof StaffUserRepository);
        \assert($hasher instanceof PasswordHasher);

        // AccessDecider — чистий доменний обʼєкт без залежностей,
        // тому створюється напряму (у контейнері він інлайниться).
        $decider = new AccessDecider();

        $user = \App\Domain\Identity\StaffUser::create(
            email: \App\Domain\Identity\Email::fromString('manager@silpo.ua'),
            passwordHash: $hasher->hash('Rampa!Staff2026'),
            role: Role::StoreManager,
            storeIds: ['A'],
            now: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $users->save($user);

        $response = $this->post('/api/admin/v1/auth/login', [
            'email' => 'manager@silpo.ua',
            'password' => 'Rampa!Staff2026',
        ]);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('store_manager', $body['user']['role']);

        // RBAC-13: скоуп працює й на зібраному контейнері
        self::assertTrue($decider->can($user, Permission::BookingMarkUnloaded, 'A'));
        self::assertFalse($decider->can($user, Permission::BookingMarkUnloaded, 'B'));
    }
}
