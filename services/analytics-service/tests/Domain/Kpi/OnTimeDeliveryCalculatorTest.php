<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kpi;

use App\Domain\Booking\BookingStatus;
use App\Domain\Kpi\OnTimeDeliveryCalculator;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KPI-02: частка бронювань, де arrived потрапив у [slotStart − 15 хв; slotEnd],
 * від усіх зі статусами completed, unloading, arrived.
 */
#[CoversClass(OnTimeDeliveryCalculator::class)]
final class OnTimeDeliveryCalculatorTest extends TestCase
{
    private OnTimeDeliveryCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new OnTimeDeliveryCalculator();
    }

    /**
     * Еталонний набір (слот 10:00–10:30), ручний розрахунок:
     * знаменник — 6 бронювань (completed/unloading/arrived),
     * у слот потрапили 3 (рівно −15 хв, рівно slotEnd, всередині) → 50%.
     * cancelled, no_show і booked у знаменник не входять.
     */
    #[Test]
    public function computesReferenceDatasetExactly(): void
    {
        $result = $this->calculator->calculate($this->referenceFacts());

        self::assertSame(6, $result->totalCount);
        self::assertSame(3, $result->onTimeCount);
        self::assertSame(50.0, $result->percent);
        self::assertSame(1, $result->earlyCount);
        self::assertSame(1, $result->lateCount);
        self::assertSame(1, $result->withoutArrivalCount);
    }

    #[Test]
    public function arrivalExactlyFifteenMinutesEarlyCountsAsOnTime(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::booking(
                bookingId: 'edge-early',
                slotStart: '2026-03-16 10:00:00',
                slotEnd: '2026-03-16 10:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 09:45:00',
            ),
        ]);

        self::assertSame(1, $result->onTimeCount);
        self::assertSame(100.0, $result->percent);
    }

    #[Test]
    public function arrivalOneSecondBeforeToleranceIsNotOnTime(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::booking(
                bookingId: 'edge-too-early',
                slotStart: '2026-03-16 10:00:00',
                slotEnd: '2026-03-16 10:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 09:44:59',
            ),
        ]);

        self::assertSame(0, $result->onTimeCount);
        self::assertSame(1, $result->earlyCount);
        self::assertSame(0.0, $result->percent);
    }

    #[Test]
    public function arrivalExactlyAtSlotEndCountsAsOnTimeButOneSecondLaterDoesNot(): void
    {
        $onEdge = $this->calculator->calculate([
            Fixtures::booking(
                bookingId: 'edge-end',
                slotStart: '2026-03-16 10:00:00',
                slotEnd: '2026-03-16 10:30:00',
                status: BookingStatus::Arrived,
                arrivedAt: '2026-03-16 10:30:00',
            ),
        ]);
        $afterEdge = $this->calculator->calculate([
            Fixtures::booking(
                bookingId: 'edge-late',
                slotStart: '2026-03-16 10:00:00',
                slotEnd: '2026-03-16 10:30:00',
                status: BookingStatus::Arrived,
                arrivedAt: '2026-03-16 10:30:01',
            ),
        ]);

        self::assertSame(1, $onEdge->onTimeCount);
        self::assertSame(0, $afterEdge->onTimeCount);
        self::assertSame(1, $afterEdge->lateCount);
    }

    #[Test]
    public function bookingsOutsideDenominatorStatusesAreIgnored(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::booking(bookingId: 'x1', status: BookingStatus::Booked),
            Fixtures::booking(bookingId: 'x2', status: BookingStatus::Cancelled),
            Fixtures::booking(bookingId: 'x3', status: BookingStatus::NoShow),
            Fixtures::booking(bookingId: 'x4', status: BookingStatus::Rejected, arrivedAt: '2026-03-16 08:00:00'),
        ]);

        self::assertSame(0, $result->totalCount);
        self::assertNull($result->percent);
        self::assertFalse($result->hasData());
    }

    #[Test]
    public function emptyDatasetReturnsNullPercent(): void
    {
        $result = $this->calculator->calculate([]);

        self::assertSame(0, $result->totalCount);
        self::assertNull($result->percent);
    }

    /**
     * @return list<\App\Domain\Fact\BookingFact>
     */
    private function referenceFacts(): array
    {
        $slotStart = '2026-03-16 10:00:00';
        $slotEnd = '2026-03-16 10:30:00';

        return [
            // у слот: рівно межа −15 хв
            Fixtures::booking(bookingId: 'b1', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Completed, arrivedAt: '2026-03-16 09:45:00'),
            // зарано на 1 секунду
            Fixtures::booking(bookingId: 'b2', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Completed, arrivedAt: '2026-03-16 09:44:59'),
            // у слот: рівно slotEnd
            Fixtures::booking(bookingId: 'b3', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Unloading, arrivedAt: '2026-03-16 10:30:00'),
            // запізно на 1 секунду
            Fixtures::booking(bookingId: 'b4', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Arrived, arrivedAt: '2026-03-16 10:30:01'),
            // статус у знаменнику, але подія arrived не зафіксована
            Fixtures::booking(bookingId: 'b5', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Arrived, arrivedAt: null),
            // у слот: усередині вікна
            Fixtures::booking(bookingId: 'b6', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Completed, arrivedAt: '2026-03-16 10:05:00'),
            // поза знаменником
            Fixtures::booking(bookingId: 'b7', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Booked),
            Fixtures::booking(bookingId: 'b8', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::Cancelled),
            Fixtures::booking(bookingId: 'b9', slotStart: $slotStart, slotEnd: $slotEnd, status: BookingStatus::NoShow),
        ];
    }
}
