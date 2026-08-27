<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Account\PartnerAccount;
use App\Domain\Account\PartnerRole;
use App\Domain\Session\LoginAttempt;
use App\Infrastructure\Mongo\MongoLoginAttemptRepository;
use App\Infrastructure\Mongo\MongoManagerFactory;
use App\Infrastructure\Mongo\MongoPartnerAccountRepository;
use App\Infrastructure\Mongo\MongoSupport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Інтеграційні тести проти реальної MongoDB (10.6).
 *
 * На машині без ext-mongodb або без піднятого сервера тести коректно
 * пропускаються — юніт-покриття партнерського контуру повністю тримається на
 * InMemory-реалізаціях.
 */
#[Group('integration')]
final class MongoRepositoriesIntegrationTest extends TestCase
{
    private const string DATABASE = 'identity_partner_test';

    private string $uri;

    protected function setUp(): void
    {
        $this->uri = $_SERVER['MONGODB_URL'] ?? $_ENV['MONGODB_URL'] ?? 'mongodb://127.0.0.1:27017';

        if (!MongoSupport::isDriverAvailable()) {
            self::markTestSkipped('Розширення ext-mongodb недоступне.');
        }

        if (!MongoManagerFactory::isServerReachable($this->uri)) {
            self::markTestSkipped(\sprintf('MongoDB за адресою %s недоступна.', $this->uri));
        }
    }

    public function testPartnerAccountRoundTrip(): void
    {
        $repository = new MongoPartnerAccountRepository(MongoManagerFactory::create($this->uri), self::DATABASE);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $account = new PartnerAccount(
            id: 'pa-'.bin2hex(random_bytes(4)),
            login: '+38067'.random_int(1000000, 9999999),
            passwordHash: '$argon2id$v=19$m=8192,t=1,p=1$abc$def',
            role: PartnerRole::Driver,
            supplierId: 'sp-integration',
            driverProfileId: 'du-integration',
            createdAt: $now,
            updatedAt: $now,
        );

        $repository->save($account);
        $loaded = $repository->findByLogin($account->login());

        self::assertNotNull($loaded);
        self::assertSame($account->id, $loaded->id);
        self::assertSame(PartnerRole::Driver, $loaded->role);
        self::assertSame('du-integration', $loaded->driverProfileId());
        self::assertTrue($loaded->isActive());

        $loaded->deactivate($now);
        $repository->save($loaded);

        $reloaded = $repository->findById($account->id);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
    }

    public function testLoginAttemptsWindowQuery(): void
    {
        // DATA-20: вибірка невдалих спроб у вікні 15 хв.
        $repository = new MongoLoginAttemptRepository(MongoManagerFactory::create($this->uri), self::DATABASE);
        $login = '+38050'.random_int(1000000, 9999999);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        for ($i = 0; $i < 5; ++$i) {
            $repository->add(LoginAttempt::failure($login, $now->modify(\sprintf('-%d seconds', $i * 10)), '10.0.0.1', 'driver-web', 'bad_password'));
        }

        $failures = $repository->findFailedSince($login, $now->modify('-900 seconds'));

        self::assertCount(5, $failures);

        $repository->clearFailuresFor($login);

        self::assertSame([], $repository->findFailedSince($login, $now->modify('-900 seconds')));
    }
}
