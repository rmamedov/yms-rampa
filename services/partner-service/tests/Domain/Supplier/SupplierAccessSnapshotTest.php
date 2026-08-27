<?php

declare(strict_types=1);

namespace App\Tests\Domain\Supplier;

use App\Domain\Supplier\StoreAccess;
use App\Domain\Supplier\SupplierAccessSnapshot;
use App\Domain\Supplier\SupplierStatus;
use App\Tests\Support\PartnerTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Правила BOOK-02 на рівні домену: статус (SUP-02) + whitelist філій (SUP-03).
 *
 * Тести працюють на InMemory-реалізаціях, без MongoDB і без HTTP.
 */
final class SupplierAccessSnapshotTest extends TestCase
{
    public function testActiveSupplierWithAllStoresAccessMayBookAnywhere(): void
    {
        $snapshot = $this->snapshotFor(StoreAccess::allStores());

        self::assertSame(SupplierStatus::Active, $snapshot->status);
        self::assertTrue($snapshot->allStores);
        // Порожній перелік разом із allStores — це «всі філії», а не «жодної».
        self::assertSame([], $snapshot->allowedStoreIds);
        self::assertTrue($snapshot->allows('S-01'));
        self::assertTrue($snapshot->allows('S-99'));
        self::assertNull($snapshot->denialReason('S-99'));
    }

    public function testWhitelistAllowsOnlyListedStores(): void
    {
        $snapshot = $this->snapshotFor(StoreAccess::whitelist(['S-02', 'S-01']));

        self::assertFalse($snapshot->allStores);
        self::assertSame(['S-01', 'S-02'], $snapshot->allowedStoreIds);
        self::assertTrue($snapshot->allows('S-01'));
        self::assertFalse($snapshot->allows('S-07'));
        self::assertSame(SupplierAccessSnapshot::REASON_STORE_NOT_ALLOWED, $snapshot->denialReason('S-07'));
    }

    /**
     * SUP-02: призупинення важливіше за whitelist — доступу немає навіть
     * до дозволеної філії, і причиною є саме статус.
     */
    public function testSuspendedSupplierHasNoAccessEvenToWhitelistedStore(): void
    {
        $env = new PartnerTestEnvironment();
        $supplier = $env->supplierService->create(
            name: 'ТОВ «Логістик Плюс»',
            storeAccess: StoreAccess::whitelist(['S-01']),
        );
        $env->supplierService->suspend($supplier->id(), 'Заборгованість');

        $snapshot = $env->supplierService->accessSnapshot($supplier->id());

        self::assertSame(SupplierStatus::Suspended, $snapshot->status);
        self::assertFalse($snapshot->isActive());
        self::assertFalse($snapshot->allows('S-01'));
        self::assertSame(SupplierAccessSnapshot::REASON_SUSPENDED, $snapshot->denialReason('S-01'));
        // Перелік дозволених філій зберігається — після activate() він знову діє.
        self::assertSame(['S-01'], $snapshot->allowedStoreIds);
    }

    /**
     * DATA-03: архівований постачальник для бронювання — призупинений,
     * навіть якщо дивитися лише на поле статусу.
     */
    public function testArchivedSupplierIsReportedAsSuspended(): void
    {
        $env = new PartnerTestEnvironment();
        $supplier = $env->supplierService->create(name: 'ТОВ «Логістик Плюс»');
        $env->supplierService->delete($supplier->id());

        $snapshot = $env->supplierService->accessSnapshot($supplier->id());

        self::assertSame(SupplierStatus::Suspended, $snapshot->status);
        self::assertFalse($snapshot->allows('S-01'));
        self::assertSame(SupplierAccessSnapshot::REASON_SUSPENDED, $snapshot->denialReason('S-01'));
    }

    private function snapshotFor(StoreAccess $access): SupplierAccessSnapshot
    {
        $env = new PartnerTestEnvironment();
        $supplier = $env->supplierService->create(name: 'ТОВ «Логістик Плюс»', storeAccess: $access);

        return $env->supplierService->accessSnapshot($supplier->id());
    }
}
