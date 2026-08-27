<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kpi;

use App\Domain\Booking\BookingStatus;
use App\Domain\Kpi\NoShowRateCalculator;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KPI-04: частка no_show від усіх бронювань, що не були cancelled.
 */
#[CoversClass(NoShowRateCalculator::class)]
final class NoShowRateCalculatorTest extends TestCase
{
    private NoShowRateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new NoShowRateCalculator();
    }

    /**
     * Еталонний набір: 2 no_show, 3 completed, 1 booked, 4 cancelled.
     * Ручний розрахунок: знаменник = 2 + 3 + 1 = 6 (cancelled виключені),
     * чисельник = 2 → 33.33%.
     */
    #[Test]
    public function computesReferenceDatasetExactly(): void
    {
        $result = $this->calculator->calculate($this->referenceFacts());

        self::assertSame(2, $result->noShowCount);
        self::assertSame(6, $result->totalCount);
        self::assertSame(4, $result->cancelledCount);
        self::assertEqualsWithDelta(33.3333, $result->percent, 0.0001);
    }

    #[Test]
    public function cancelledBookingsAreExcludedFromBothNumeratorAndDenominator(): void
    {
        $onlyCancelled = $this->calculator->calculate([
            Fixtures::booking(bookingId: 'c1', status: BookingStatus::Cancelled),
            Fixtures::booking(bookingId: 'c2', status: BookingStatus::Cancelled),
        ]);

        self::assertSame(0, $onlyCancelled->totalCount);
        self::assertNull($onlyCancelled->percent);
        self::assertFalse($onlyCancelled->hasData());
    }

    #[Test]
    public function rejectedAndWalkInBookingsStayInDenominator(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::booking(bookingId: 'r1', status: BookingStatus::Rejected),
            Fixtures::booking(bookingId: 'n1', status: BookingStatus::NoShow),
        ]);

        self::assertSame(2, $result->totalCount);
        self::assertSame(1, $result->noShowCount);
        self::assertSame(50.0, $result->percent);
    }

    #[Test]
    public function emptyDatasetReturnsNullPercent(): void
    {
        $result = $this->calculator->calculate([]);

        self::assertSame(0, $result->totalCount);
        self::assertNull($result->percent);
    }

    #[Test]
    public function allNoShowGivesHundredPercent(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::booking(bookingId: 'n1', status: BookingStatus::NoShow),
            Fixtures::booking(bookingId: 'n2', status: BookingStatus::NoShow),
        ]);

        self::assertSame(100.0, $result->percent);
    }

    /**
     * @return list<\App\Domain\Fact\BookingFact>
     */
    private function referenceFacts(): array
    {
        return [
            Fixtures::booking(bookingId: 'n1', status: BookingStatus::NoShow),
            Fixtures::booking(bookingId: 'n2', status: BookingStatus::NoShow),
            Fixtures::booking(bookingId: 'c1', status: BookingStatus::Completed),
            Fixtures::booking(bookingId: 'c2', status: BookingStatus::Completed),
            Fixtures::booking(bookingId: 'c3', status: BookingStatus::Completed),
            Fixtures::booking(bookingId: 'b1', status: BookingStatus::Booked),
            Fixtures::booking(bookingId: 'x1', status: BookingStatus::Cancelled),
            Fixtures::booking(bookingId: 'x2', status: BookingStatus::Cancelled),
            Fixtures::booking(bookingId: 'x3', status: BookingStatus::Cancelled),
            Fixtures::booking(bookingId: 'x4', status: BookingStatus::Cancelled),
        ];
    }
}
