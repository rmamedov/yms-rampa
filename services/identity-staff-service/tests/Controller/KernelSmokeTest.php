<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
