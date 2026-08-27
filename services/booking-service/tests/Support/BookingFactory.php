<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\StoreSnapshot;
use App\Domain\Booking\VehicleSnapshot;
use DateTimeImmutable;

/**
 * Швидке створення агрегату Booking для доменних тестів машини станів.
 */
final class BookingFactory
{
    public static function snapshot(): StoreSnapshot
    {
        return new StoreSnapshot('1998', 'Сільпо Хрещатик', 'Київ', 'вул. Хрещатик, 12');
    }

    public static function scheduled(
        string $localDateTime = '2026-08-28 10:00',
        ?string $driverId = null,
        int $palletsCount = 8,
        ?VehicleSnapshot $vehicle = null,
        string $supplierId = Scenario::SUPPLIER_ID,
        ?DateTimeImmutable $createdAt = null,
        string $id = 'bk-test',
    ): Booking {
        $slotStart = Scenario::kyiv($localDateTime);

        return Booking::schedule(
            id: $id,
            storeId: Scenario::STORE_ID,
            storeSnapshot: self::snapshot(),
            rampId: 'r1',
            slotStart: $slotStart,
            slotEnd: $slotStart->modify('+30 minutes'),
            supplierId: $supplierId,
            supplierNameSnapshot: 'ТОВ Молокія',
            vehicle: $vehicle ?? Scenario::vehicle(),
            palletsCount: $palletsCount,
            createdBy: new Actor('pu-1', Role::SupplierAdmin, supplierId: $supplierId),
            now: $createdAt ?? Scenario::kyiv('2026-08-27 09:00'),
            driverId: $driverId,
        );
    }

    public static function walkIn(string $localDateTime = '2026-08-27 09:00', ?string $supplierId = Scenario::SUPPLIER_ID): Booking
    {
        $slotStart = Scenario::kyiv($localDateTime);

        return Booking::walkIn(
            id: 'bk-walkin',
            storeId: Scenario::STORE_ID,
            storeSnapshot: self::snapshot(),
            rampId: 'r2',
            slotStart: $slotStart,
            slotEnd: $slotStart->modify('+30 minutes'),
            supplierId: $supplierId,
            supplierNameSnapshot: 'ТОВ Молокія',
            vehicle: Scenario::vehicle('BC5555CT', 3.5),
            palletsCount: 4,
            createdBy: new Actor('su-1', Role::StoreOperator, storeIds: [Scenario::STORE_ID]),
            now: Scenario::kyiv('2026-08-27 09:05'),
        );
    }
}
