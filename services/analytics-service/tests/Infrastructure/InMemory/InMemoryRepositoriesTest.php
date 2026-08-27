<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\InMemory;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Slot\SlotState;
use App\Domain\Stats\DailyStoreStatsBuilder;
use App\Infrastructure\InMemory\InMemoryBookingFactRepository;
use App\Infrastructure\InMemory\InMemoryDailyStoreStatsRepository;
use App\Infrastructure\InMemory\InMemorySlotFactRepository;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * InMemory-реалізації доменних репозиторіїв: юніт-тести працюють
 * без MongoDB і Redis.
 */
#[CoversClass(InMemoryBookingFactRepository::class)]
#[CoversClass(InMemorySlotFactRepository::class)]
#[CoversClass(InMemoryDailyStoreStatsRepository::class)]
final class InMemoryRepositoriesTest extends TestCase
{
    #[Test]
    public function bookingRepositoryUpsertsByBookingIdWithoutDuplicating(): void
    {
        $repository = new InMemoryBookingFactRepository();
        $repository->save(Fixtures::booking(bookingId: 'b1'));
        $repository->save(Fixtures::booking(bookingId: 'b1', city: 'Львів'));

        self::assertSame(1, $repository->countAll());
        self::assertSame('Львів', $repository->findByBookingId('b1')?->city);
        self::assertNull($repository->findByBookingId('missing'));
    }

    #[Test]
    public function bookingRepositoryAppliesDashboardFilters(): void
    {
        $repository = new InMemoryBookingFactRepository([
            Fixtures::booking(bookingId: 'in-1', city: 'Київ', slotStart: '2026-03-16 08:00:00', slotEnd: '2026-03-16 08:30:00'),
            Fixtures::booking(bookingId: 'in-2', city: 'Київ', slotStart: '2026-03-16 12:00:00', slotEnd: '2026-03-16 12:30:00'),
            Fixtures::booking(bookingId: 'other-city', city: 'Львів', slotStart: '2026-03-16 08:00:00', slotEnd: '2026-03-16 08:30:00'),
            Fixtures::booking(bookingId: 'out-of-period', city: 'Київ', slotStart: '2026-03-20 08:00:00', slotEnd: '2026-03-20 08:30:00'),
        ]);

        $found = $repository->findByQuery(new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
            cities: ['Київ'],
        ));

        self::assertSame(['in-1', 'in-2'], array_map(static fn ($fact): string => $fact->bookingId, $found));
    }

    /**
     * ANL-14: мітка recalculatedAt береться з найсвіжішого оновлення факту.
     */
    #[Test]
    public function bookingRepositoryReportsLastUpdatedAt(): void
    {
        $repository = new InMemoryBookingFactRepository();
        self::assertNull($repository->lastUpdatedAt());

        $repository->save(Fixtures::booking(bookingId: 'b1', updatedAt: '2026-03-16 10:00:00'));
        $repository->save(Fixtures::booking(bookingId: 'b2', updatedAt: '2026-03-16 12:30:00'));

        self::assertSame('2026-03-16 12:30:00', $repository->lastUpdatedAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function slotRepositoryFiltersByPeriodStoreAndRamp(): void
    {
        $repository = new InMemorySlotFactRepository();
        $repository->saveMany([
            Fixtures::slot('s1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00', rampId: 'ramp-1'),
            Fixtures::slot('s2', SlotState::Booked, '2026-03-16 08:30:00', '2026-03-16 09:00:00', rampId: 'ramp-2'),
            Fixtures::slot('s3', SlotState::Booked, '2026-03-18 08:00:00', '2026-03-18 08:30:00', rampId: 'ramp-1'),
        ]);

        $found = $repository->findByQuery(new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
            storeIds: ['store-1'],
            rampIds: ['ramp-1'],
        ));

        self::assertCount(1, $found);
        self::assertSame('s1', $found[0]->slotId);
        self::assertSame(3, $repository->countAll());
    }

    #[Test]
    public function dailyStatsRepositoryStoresAndFiltersAggregates(): void
    {
        $stats = (new DailyStoreStatsBuilder())->build(
            [
                Fixtures::booking(bookingId: 'a1', storeId: 'store-1', slotStart: '2026-03-16 08:00:00', slotEnd: '2026-03-16 08:30:00'),
                Fixtures::booking(bookingId: 'b1', storeId: 'store-2', city: 'Львів', slotStart: '2026-03-16 08:00:00', slotEnd: '2026-03-16 08:30:00'),
            ],
            [],
            Fixtures::utc('2026-03-16 09:00:00'),
        );

        $repository = new InMemoryDailyStoreStatsRepository();
        $repository->saveMany($stats);

        self::assertNotNull($repository->find('store-1', '2026-03-16'));
        self::assertSame('2026-03-16 09:00:00', $repository->lastRecalculatedAt()?->format('Y-m-d H:i:s'));

        $filtered = $repository->findByQuery(new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-16 23:59:59'),
            cities: ['Львів'],
        ));

        self::assertCount(1, $filtered);
        self::assertSame('store-2', $filtered[0]->storeId);
    }
}
