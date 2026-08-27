<?php

declare(strict_types=1);

namespace App\Tests\Domain\Analytics;

use App\Domain\Analytics\BreakdownCalculator;
use App\Domain\Analytics\BreakdownRow;
use App\Domain\Analytics\Dimension;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;
use App\Domain\Slot\SlotState;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KPI-05: розрізи мережа / місто / магазин / постачальник /
 * день-тиждень-місяць, тип бронювання і причини відмов.
 */
#[CoversClass(BreakdownCalculator::class)]
#[CoversClass(Dimension::class)]
final class BreakdownCalculatorTest extends TestCase
{
    private BreakdownCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BreakdownCalculator();
    }

    #[Test]
    public function splitsByCityAndKeepsUtilizationPerCity(): void
    {
        $rows = $this->calculator->calculate($this->facts(), $this->slots(), Dimension::City);

        self::assertSame(['Київ', 'Львів'], array_map(static fn (BreakdownRow $r): string => $r->key, $rows));

        $kyiv = $this->row($rows, 'Київ');
        $lviv = $this->row($rows, 'Львів');

        self::assertSame(3, $kyiv->kpi->counters->total);
        self::assertSame(1, $lviv->kpi->counters->total);
        // Київ: 60 заброньованих хв із 90 доступних = 66.67%
        self::assertEqualsWithDelta(66.6667, $kyiv->kpi->utilization->percent, 0.0001);
        // Львів: 30 із 30 = 100%
        self::assertSame(100.0, $lviv->kpi->utilization->percent);
    }

    #[Test]
    public function splitsBySupplierAndLeavesUtilizationEmptyBecauseSlotsHaveNoSupplier(): void
    {
        $rows = $this->calculator->calculate($this->facts(), $this->slots(), Dimension::Supplier);

        self::assertSame(['sup-1', 'sup-2'], array_map(static fn (BreakdownRow $r): string => $r->key, $rows));
        self::assertSame(3, $this->row($rows, 'sup-1')->kpi->counters->total);
        self::assertNull($this->row($rows, 'sup-1')->kpi->utilization->percent);
    }

    /**
     * Доба рахується в локальній зоні магазину Europe/Kyiv: слот, що починається
     * о 22:30 UTC 16 березня, належить добі 17 березня.
     */
    #[Test]
    public function splitsByLocalStoreDayNotUtcDay(): void
    {
        $facts = [
            Fixtures::booking(bookingId: 'd1', slotStart: '2026-03-16 09:00:00', slotEnd: '2026-03-16 09:30:00'),
            Fixtures::booking(bookingId: 'd2', slotStart: '2026-03-16 22:30:00', slotEnd: '2026-03-16 23:00:00'),
        ];

        $rows = $this->calculator->calculate($facts, [], Dimension::Day);

        self::assertSame(['2026-03-16', '2026-03-17'], array_map(static fn (BreakdownRow $r): string => $r->key, $rows));
    }

    #[Test]
    public function splitsByWeekAndMonth(): void
    {
        $facts = [
            Fixtures::booking(bookingId: 'w1', slotStart: '2026-03-16 09:00:00', slotEnd: '2026-03-16 09:30:00'),
            Fixtures::booking(bookingId: 'w2', slotStart: '2026-03-24 09:00:00', slotEnd: '2026-03-24 09:30:00'),
            Fixtures::booking(bookingId: 'w3', slotStart: '2026-04-01 09:00:00', slotEnd: '2026-04-01 09:30:00'),
        ];

        $weeks = $this->calculator->calculate($facts, [], Dimension::Week);
        $months = $this->calculator->calculate($facts, [], Dimension::Month);

        self::assertSame(['2026-W12', '2026-W13', '2026-W14'], array_map(static fn (BreakdownRow $r): string => $r->key, $weeks));
        self::assertSame(['2026-03', '2026-04'], array_map(static fn (BreakdownRow $r): string => $r->key, $months));
        self::assertSame(2, $this->row($months, '2026-03')->kpi->counters->total);
    }

    /**
     * Окремий розріз walk_in vs scheduled.
     */
    #[Test]
    public function splitsByBookingType(): void
    {
        $rows = $this->calculator->calculate($this->facts(), [], Dimension::Type);

        self::assertSame(['scheduled', 'walk_in'], array_map(static fn (BreakdownRow $r): string => $r->key, $rows));
        self::assertSame(3, $this->row($rows, 'scheduled')->kpi->counters->total);
        self::assertSame(1, $this->row($rows, 'walk_in')->kpi->counters->total);
    }

    /**
     * Розріз за причинами відмов охоплює лише бронювання зі статусом rejected.
     */
    #[Test]
    public function splitsByRejectionReasonAndSkipsBookingsWithoutReason(): void
    {
        $facts = [
            Fixtures::booking(bookingId: 'r1', status: BookingStatus::Rejected, rejectedReason: RejectionReason::WeightExceeded),
            Fixtures::booking(bookingId: 'r2', status: BookingStatus::Rejected, rejectedReason: RejectionReason::WeightExceeded),
            Fixtures::booking(bookingId: 'r3', status: BookingStatus::Rejected, rejectedReason: RejectionReason::DocumentsMissing),
            Fixtures::booking(bookingId: 'ok', status: BookingStatus::Completed),
        ];

        $rows = $this->calculator->calculate($facts, [], Dimension::RejectionReason);

        self::assertSame(
            ['documents_missing', 'weight_exceeded'],
            array_map(static fn (BreakdownRow $r): string => $r->key, $rows),
        );
        self::assertSame(2, $this->row($rows, 'weight_exceeded')->kpi->counters->total);
    }

    #[Test]
    public function networkDimensionAggregatesEverythingIntoSingleRow(): void
    {
        $rows = $this->calculator->calculate($this->facts(), $this->slots(), Dimension::Network);

        self::assertCount(1, $rows);
        self::assertSame('network', $rows[0]->key);
        self::assertSame(4, $rows[0]->kpi->counters->total);
        // 90 заброньованих хв зі 120 доступних = 75%
        self::assertSame(75.0, $rows[0]->kpi->utilization->percent);
    }

    #[Test]
    public function emptyDatasetProducesNoRows(): void
    {
        self::assertSame([], $this->calculator->calculate([], [], Dimension::Store));
    }

    /**
     * Група, у якій є слоти, але немає бронювань, усе одно потрапляє у вибірку
     * з нульовою утилізацією — інакше «порожній» магазин зник би з дашборда.
     */
    #[Test]
    public function groupWithSlotsButWithoutBookingsIsStillReported(): void
    {
        $slots = [
            Fixtures::slot('empty-1', SlotState::Available, '2026-03-16 08:00:00', '2026-03-16 08:30:00', storeId: 'store-9'),
        ];

        $rows = $this->calculator->calculate([], $slots, Dimension::Store);

        self::assertCount(1, $rows);
        self::assertSame('store-9', $rows[0]->key);
        self::assertSame(0, $rows[0]->kpi->counters->total);
        self::assertSame(0.0, $rows[0]->kpi->utilization->percent);
    }

    /**
     * @return list<\App\Domain\Fact\BookingFact>
     */
    private function facts(): array
    {
        return [
            Fixtures::booking(bookingId: 'k1', city: 'Київ', storeId: 'store-1', supplierId: 'sup-1', status: BookingStatus::Completed),
            Fixtures::booking(bookingId: 'k2', city: 'Київ', storeId: 'store-1', supplierId: 'sup-1', status: BookingStatus::NoShow),
            Fixtures::booking(bookingId: 'k3', city: 'Київ', storeId: 'store-2', supplierId: 'sup-1', type: BookingType::WalkIn, status: BookingStatus::Completed),
            Fixtures::booking(bookingId: 'l1', city: 'Львів', storeId: 'store-3', supplierId: 'sup-2', status: BookingStatus::Completed),
        ];
    }

    /**
     * @return list<\App\Domain\Slot\SlotFact>
     */
    private function slots(): array
    {
        return [
            Fixtures::slot('ks1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00', storeId: 'store-1', city: 'Київ'),
            Fixtures::slot('ks2', SlotState::Booked, '2026-03-16 08:30:00', '2026-03-16 09:00:00', storeId: 'store-1', city: 'Київ'),
            Fixtures::slot('ks3', SlotState::Available, '2026-03-16 09:00:00', '2026-03-16 09:30:00', storeId: 'store-2', city: 'Київ'),
            Fixtures::slot('ls1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00', storeId: 'store-3', city: 'Львів'),
        ];
    }

    /**
     * @param list<BreakdownRow> $rows
     */
    private function row(array $rows, string $key): BreakdownRow
    {
        foreach ($rows as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        self::fail(sprintf('У розрізі немає рядка «%s».', $key));
    }
}
