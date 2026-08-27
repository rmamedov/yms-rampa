<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Identity\Email;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Infrastructure\Mongo\MongoConnection;
use App\Infrastructure\Mongo\MongoStaffUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Інтеграційний тест проти РЕАЛЬНОЇ MongoDB (колекція `staff_users`, 10.5).
 *
 * Тест ПРОПУСКАЄТЬСЯ, якщо немає розширення ext-mongodb або сервер недоступний,
 * тому збірка на машині без MongoDB лишається зеленою.
 */
#[Group('integration')]
#[CoversClass(MongoStaffUserRepository::class)]
#[CoversClass(MongoConnection::class)]
final class MongoStaffUserRepositoryTest extends TestCase
{
    private ?MongoConnection $connection = null;

    protected function setUp(): void
    {
        if (!MongoConnection::isDriverAvailable()) {
            self::markTestSkipped('Розширення ext-mongodb не встановлено.');
        }

        $uri = (string) ($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017');
        $database = (string) ($_ENV['MONGODB_DB'] ?? 'identity_staff_test');

        try {
            $connection = new MongoConnection($uri, $database);
            // Перевірка живого зʼєднання: без сервера драйвер кине ConnectionTimeoutException
            $connection->count('staff_users');
        } catch (\Throwable $exception) {
            self::markTestSkipped('MongoDB недоступна: '.$exception->getMessage());
        }

        $this->connection = $connection;
        $this->connection->deleteAll('staff_users');
    }

    public function testRoundTripPreservesAggregateState(): void
    {
        self::assertNotNull($this->connection);
        $repository = new MongoStaffUserRepository($this->connection);
        $now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');

        $user = StaffUser::create(
            email: Email::fromString('manager@silpo.ua'),
            passwordHash: '$argon2id$fake',
            role: Role::StoreManager,
            storeIds: ['A', 'B'],
            now: $now,
            fullName: 'Олена Іванова',
        );
        $user->registerSuccessfulLogin($now);

        $repository->save($user);

        $loaded = $repository->findByEmail(Email::fromString('MANAGER@silpo.ua'));

        self::assertNotNull($loaded);
        self::assertSame($user->id(), $loaded->id());
        self::assertSame(Role::StoreManager, $loaded->role());
        self::assertSame(['A', 'B'], $loaded->storeIds());
        self::assertTrue($loaded->isActive());
        self::assertEquals($now, $loaded->lastLoginAt());
        self::assertSame(StaffUser::SCHEMA_VERSION, $loaded->schemaVersion());
    }

    /**
     * RBAC-17: скоуп-фільтр — предикат ЗАПИТУ; порожній масив дає порожню вибірку.
     */
    public function testStoreScopeFilterIsAppliedInQuery(): void
    {
        self::assertNotNull($this->connection);
        $repository = new MongoStaffUserRepository($this->connection);
        $now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');

        $repository->save(StaffUser::create(Email::fromString('a@silpo.ua'), 'h', Role::StoreManager, ['A'], $now));
        $repository->save(StaffUser::create(Email::fromString('b@silpo.ua'), 'h', Role::StoreOperator, ['B'], $now));
        $repository->save(StaffUser::create(Email::fromString('n@silpo.ua'), 'h', Role::NetworkManager, [], $now));

        self::assertCount(1, $repository->findByStoreScope(['A']));
        self::assertCount(2, $repository->findByStoreScope(['A', 'B']));
        self::assertCount(0, $repository->findByStoreScope([]));
        self::assertCount(3, $repository->findByStoreScope(null));
        self::assertSame(1, $repository->countActiveByRole(Role::NetworkManager));
    }
}
