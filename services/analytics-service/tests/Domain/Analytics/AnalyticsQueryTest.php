<?php

declare(strict_types=1);

namespace App\Tests\Domain\Analytics;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\PeriodBucket;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Slot\SlotState;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ANL-10: фільтри період / місто / магазин / постачальник застосовуються
 * до всіх віджетів дашборда однаково.
 */
#[CoversClass(AnalyticsQuery::class)]
#[CoversClass(PeriodBucket::class)]
final class AnalyticsQueryTest extends TestCase
{
    #[Test]
    public function periodIsHalfOpenInterval(): void
    {
        $query = new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
        );

        $onLowerBound = Fixtures::booking(bookingId: 'a', slotStart: '2026-03-16 00:00:00', slotEnd: '2026-03-16 00:30:00');
        $insideRange = Fixtures::booking(bookingId: 'b', slotStart: '2026-03-16 23:59:59', slotEnd: '2026-03-17 00:29:59');
        $onUpperBound = Fixtures::booking(bookingId: 'c', slotStart: '2026-03-17 00:00:00', slotEnd: '2026-03-17 00:30:00');

        self::assertTrue($query->matchesFact($onLowerBound));
        self::assertTrue($query->matchesFact($insideRange));
        self::assertFalse($query->matchesFact($onUpperBound));
    }

    #[Test]
    public function combinesCityStoreSupplierAndTypeFilters(): void
    {
        $query = new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
            cities: ['Київ'],
            storeIds: ['store-1'],
            supplierIds: ['sup-1'],
            types: [BookingType::Scheduled],
            statuses: [BookingStatus::Completed],
        );

        $matching = Fixtures::booking(bookingId: 'ok', city: 'Київ', storeId: 'store-1', supplierId: 'sup-1', status: BookingStatus::Completed);

        self::assertTrue($query->matchesFact($matching));
        self::assertFalse($query->matchesFact(Fixtures::booking(bookingId: 'x1', city: 'Львів')));
        self::assertFalse($query->matchesFact(Fixtures::booking(bookingId: 'x2', storeId: 'store-2')));
        self::assertFalse($query->matchesFact(Fixtures::booking(bookingId: 'x3', supplierId: 'sup-9')));
        self::assertFalse($query->matchesFact(Fixtures::booking(bookingId: 'x4', type: BookingType::WalkIn)));
        self::assertFalse($query->matchesFact(Fixtures::booking(bookingId: 'x5', status: BookingStatus::NoShow)));
    }

    /**
     * До слотів застосовуються лише розрізи, що мають сенс для слота:
     * фільтр постачальника не має відсікати інвентар слотів.
     */
    #[Test]
    public function supplierFilterDoesNotApplyToSlots(): void
    {
        $query = new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
            supplierIds: ['sup-1'],
        );

        self::assertTrue($query->matchesSlot(
            Fixtures::slot('s1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00'),
        ));
        self::assertFalse($query->matchesSlot(
            Fixtures::slot('s2', SlotState::Booked, '2026-03-18 08:00:00', '2026-03-18 08:30:00'),
        ));
    }

    #[Test]
    public function describeListsAppliedFiltersForCsvHeader(): void
    {
        $query = new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
            cities: ['Київ', 'Львів'],
            storeIds: ['store-1'],
        );

        $description = $query->describe();

        self::assertStringContainsString('період: 2026-03-16 00:00 — 2026-03-17 00:00', $description);
        self::assertStringContainsString('міста: Київ|Львів', $description);
        self::assertStringContainsString('магазини: store-1', $description);
    }

    #[Test]
    public function periodBucketUsesStoreLocalTimeZone(): void
    {
        $lateEvening = Fixtures::utc('2026-03-16 22:30:00');

        self::assertSame('2026-03-17', PeriodBucket::day($lateEvening));
        self::assertSame('2026-03', PeriodBucket::month($lateEvening));
        self::assertSame('Europe/Kyiv', PeriodBucket::storeTimeZone()->getName());
    }
}
