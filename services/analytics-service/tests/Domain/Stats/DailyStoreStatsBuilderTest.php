<?php

declare(strict_types=1);

namespace App\Tests\Domain\Stats;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Slot\SlotState;
use App\Domain\Stats\DailyStoreStats;
use App\Domain\Stats\DailyStoreStatsBuilder;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Агрегат «магазин × доба»: ті самі канонічні формули KPI, згорнуті по добах.
 */
#[CoversClass(DailyStoreStatsBuilder::class)]
#[CoversClass(DailyStoreStats::class)]
final class DailyStoreStatsBuilderTest extends TestCase
{
    private DailyStoreStatsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DailyStoreStatsBuilder();
    }

    #[Test]
    public function buildsOneRowPerStoreAndLocalDay(): void
    {
        $stats = $this->builder->build($this->facts(), $this->slots(), Fixtures::utc('2026-03-17 03:00:00'));

        $keys = array_map(static fn (DailyStoreStats $s): string => $s->id(), $stats);

        self::assertSame(
            ['store-1:2026-03-16', 'store-1:2026-03-17', 'store-2:2026-03-16'],
            $keys,
        );
    }

    /**
     * Ручний розрахунок для store-1 за 16 березня:
     * 3 бронювання (2 completed, 1 no_show), 1 walk-in, 1 затримка;
     * слото-хвилини — 60 booked із 90 доступних = 66.67%;
     * no-show rate = 1/3 = 33.33%.
     */
    #[Test]
    public function computesReferenceDayExactly(): void
    {
        $stats = $this->builder->build($this->facts(), $this->slots(), Fixtures::utc('2026-03-17 03:00:00'));
        $row = $this->row($stats, 'store-1:2026-03-16');

        self::assertSame(3, $row->bookingsTotal);
        self::assertSame(2, $row->completedCount);
        self::assertSame(1, $row->noShowCount);
        self::assertSame(1, $row->walkInCount);
        self::assertSame(2, $row->scheduledCount);
        self::assertSame(1, $row->delayedCount);
        self::assertSame(60.0, $row->bookedMinutes);
        self::assertSame(90.0, $row->availableMinutes);
        self::assertEqualsWithDelta(66.6667, $row->utilizationPercent, 0.0001);
        self::assertEqualsWithDelta(33.3333, $row->noShowPercent, 0.0001);
        self::assertSame('Київ', $row->city);
        self::assertSame('2026-03-17T03:00:00+00:00', $row->recalculatedAt->format(\DATE_ATOM));
    }

    #[Test]
    public function dayWithSlotsButWithoutBookingsHasZeroUtilizationNotNull(): void
    {
        $stats = $this->builder->build([], [
            Fixtures::slot('s-empty', SlotState::Available, '2026-03-16 08:00:00', '2026-03-16 08:30:00'),
        ], Fixtures::utc('2026-03-16 09:00:00'));

        self::assertCount(1, $stats);
        self::assertSame(0, $stats[0]->bookingsTotal);
        self::assertSame(0.0, $stats[0]->utilizationPercent);
        self::assertNull($stats[0]->noShowPercent, 'Порожній знаменник KPI-04 дає null, а не нуль');
    }

    #[Test]
    public function emptyInputProducesNoRows(): void
    {
        self::assertSame([], $this->builder->build([], [], Fixtures::utc('2026-03-16 09:00:00')));
    }

    #[Test]
    public function serializesToArrayWithRoundedValues(): void
    {
        $stats = $this->builder->build($this->facts(), $this->slots(), Fixtures::utc('2026-03-17 03:00:00'));
        $array = $this->row($stats, 'store-1:2026-03-16')->toArray();

        self::assertSame(66.67, $array['utilizationPercent']);
        self::assertSame(33.33, $array['noShowPercent']);
        self::assertSame('2026-03-16', $array['date']);
    }

    /**
     * @return list<\App\Domain\Fact\BookingFact>
     */
    private function facts(): array
    {
        return [
            Fixtures::booking(
                bookingId: 'a1',
                storeId: 'store-1',
                slotStart: '2026-03-16 08:00:00',
                slotEnd: '2026-03-16 08:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 07:55:00',
                unloadingStartedAt: '2026-03-16 08:05:00',
                completedAt: '2026-03-16 08:25:00',
            ),
            Fixtures::booking(
                bookingId: 'a2',
                storeId: 'store-1',
                slotStart: '2026-03-16 08:30:00',
                slotEnd: '2026-03-16 09:00:00',
                type: BookingType::WalkIn,
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 08:30:00',
                unloadingStartedAt: '2026-03-16 08:40:00',
                completedAt: '2026-03-16 08:55:00',
                delayed: true,
                delayReason: 'Затор на трасі',
            ),
            Fixtures::booking(
                bookingId: 'a3',
                storeId: 'store-1',
                slotStart: '2026-03-16 09:00:00',
                slotEnd: '2026-03-16 09:30:00',
                status: BookingStatus::NoShow,
            ),
            // інша доба того самого магазину
            Fixtures::booking(
                bookingId: 'a4',
                storeId: 'store-1',
                slotStart: '2026-03-17 08:00:00',
                slotEnd: '2026-03-17 08:30:00',
                status: BookingStatus::Completed,
            ),
            // інший магазин
            Fixtures::booking(
                bookingId: 'b1',
                storeId: 'store-2',
                slotStart: '2026-03-16 08:00:00',
                slotEnd: '2026-03-16 08:30:00',
                status: BookingStatus::Cancelled,
            ),
        ];
    }

    /**
     * @return list<\App\Domain\Slot\SlotFact>
     */
    private function slots(): array
    {
        return [
            Fixtures::slot('s1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00', storeId: 'store-1'),
            Fixtures::slot('s2', SlotState::Booked, '2026-03-16 08:30:00', '2026-03-16 09:00:00', storeId: 'store-1'),
            Fixtures::slot('s3', SlotState::Available, '2026-03-16 09:00:00', '2026-03-16 09:30:00', storeId: 'store-1'),
            Fixtures::slot('s4', SlotState::Blocked, '2026-03-16 09:30:00', '2026-03-16 10:00:00', storeId: 'store-1'),
            Fixtures::slot('s5', SlotState::Available, '2026-03-17 08:00:00', '2026-03-17 08:30:00', storeId: 'store-1'),
            Fixtures::slot('s6', SlotState::Available, '2026-03-16 08:00:00', '2026-03-16 08:30:00', storeId: 'store-2'),
        ];
    }

    /**
     * @param list<DailyStoreStats> $stats
     */
    private function row(array $stats, string $id): DailyStoreStats
    {
        foreach ($stats as $item) {
            if ($item->id() === $id) {
                return $item;
            }
        }

        self::fail(sprintf('Немає агрегату «%s».', $id));
    }
}
