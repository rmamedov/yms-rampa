<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\PartnerUser\PartnerUser;
use App\Domain\Shared\ConflictException;
use App\Domain\Supplier\Supplier;
use App\Domain\Vehicle\Vehicle;
use App\Infrastructure\Mongo\MongoConnection;
use App\Infrastructure\Mongo\MongoIndexInstaller;
use App\Infrastructure\Mongo\MongoPartnerUserRepository;
use App\Infrastructure\Mongo\MongoSupplierRepository;
use App\Infrastructure\Mongo\MongoVehicleRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Інтеграційні перевірки проти реальної MongoDB.
 *
 * Тест НЕ повинен падати на машині без розширення ext-mongodb або без
 * піднятого сервера — у такому разі він коректно пропускається.
 * Запуск: `vendor/bin/phpunit --group integration` з піднятим Mongo.
 */
#[Group('integration')]
final class MongoRepositoriesIntegrationTest extends TestCase
{
    private MongoConnection $connection;

    protected function setUp(): void
    {
        if (!MongoConnection::isDriverAvailable()) {
            self::markTestSkipped('Розширення PHP «mongodb» не встановлено.');
        }

        $dsn = $_SERVER['MONGO_DSN'] ?? 'mongodb://127.0.0.1:27017';
        $this->connection = new MongoConnection((string) $dsn, 'partners_test');

        if (!$this->connection->isServerReachable()) {
            self::markTestSkipped('Сервер MongoDB недоступний.');
        }

        $this->dropCollections();
        (new MongoIndexInstaller($this->connection))->install();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isServerReachable()) {
            $this->dropCollections();
        }
    }

    /**
     * DATA-18: unique compound {supplierId, plateNumber, archivedAt} —
     * дублікат у межах постачальника відхиляється самою базою.
     */
    public function testCompoundUniqueIndexRejectsDuplicatePlateWithinSupplier(): void
    {
        $repository = new MongoVehicleRepository($this->connection);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $repository->save(new Vehicle('vh-1', 'sp-1', 'AA1234BB', 12.0, null, $now));

        $this->expectException(ConflictException::class);

        $repository->save(new Vehicle('vh-2', 'sp-1', 'AA1234BB', 12.0, null, $now));
    }

    /**
     * SUP-VEH-02: той самий номер у різного постачальника — не конфлікт.
     */
    public function testSamePlateIsAllowedForDifferentSuppliers(): void
    {
        $repository = new MongoVehicleRepository($this->connection);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $repository->save(new Vehicle('vh-1', 'sp-1', 'AA1234BB', 12.0, null, $now));
        $repository->save(new Vehicle('vh-2', 'sp-2', 'AA1234BB', 12.0, null, $now));

        self::assertNotNull($repository->findBySupplierAndPlate('sp-1', 'AA1234BB'));
        self::assertNotNull($repository->findBySupplierAndPlate('sp-2', 'AA1234BB'));
        self::assertNull($repository->findBySupplierAndPlate('sp-3', 'AA1234BB'));
    }

    /**
     * DATA-17: unique partial {phone} для активних водіїв — глобально.
     */
    public function testDriverPhoneUniqueIndexIsGlobal(): void
    {
        $repository = new MongoPartnerUserRepository($this->connection);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $repository->save(PartnerUser::driver('du-1', 'pa-1', 'sp-1', '+380671112233', 'Іван', 'Коваль', null, $now));

        $this->expectException(ConflictException::class);

        $repository->save(PartnerUser::driver('du-2', 'pa-2', 'sp-2', '+380671112233', 'Петро', 'Шевчук', null, $now));
    }

    public function testSupplierRoundTripPreservesFields(): void
    {
        $repository = new MongoSupplierRepository($this->connection);
        $now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');

        $supplier = new Supplier('sp-1', 'ТОВ «Логістик Плюс»', '12345678', null, [], $now);
        $supplier->suspend($now, 'Заборгованість');
        $repository->save($supplier);

        $loaded = $repository->findById('sp-1');

        self::assertNotNull($loaded);
        self::assertSame('ТОВ «Логістик Плюс»', $loaded->name());
        self::assertSame('12345678', $loaded->edrpou());
        self::assertFalse($loaded->isActive());
        self::assertSame('Заборгованість', $loaded->suspendReason());
        self::assertSame($now->getTimestamp(), $loaded->createdAt()->getTimestamp());
        self::assertNotNull($repository->findByName('тов «логістик плюс»'));
        self::assertSame(1, $repository->count());
    }

    private function dropCollections(): void
    {
        foreach (['suppliers', 'partner_users', 'vehicles', 'outbox'] as $collection) {
            try {
                $this->connection->manager()->executeCommand(
                    $this->connection->database(),
                    new \MongoDB\Driver\Command(['drop' => $collection]),
                );
            } catch (\Throwable) {
                // Колекції може не існувати — це нормально.
            }
        }
    }
}
