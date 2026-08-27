<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kpi;

use App\Domain\Booking\BookingStatus;
use App\Domain\Kpi\WaitingTimeCalculator;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KPI-03: середній і медіанний час очікування від arrived до unloading.
 */
#[CoversClass(WaitingTimeCalculator::class)]
final class WaitingTimeCalculatorTest extends TestCase
{
    private WaitingTimeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new WaitingTimeCalculator();
    }

    /**
     * Еталонний набір: очікування 10, 20, 30 і 60 хв.
     * Ручний розрахунок: середнє = (10+20+30+60)/4 = 30 хв;
     * медіана (парна кількість) = (20+30)/2 = 25 хв.
     */
    #[Test]
    public function computesAverageAndMedianForEvenSample(): void
    {
        $result = $this->calculator->calculate([
            $this->waiting('b1', '09:00:00', '09:10:00'),
            $this->waiting('b2', '09:00:00', '09:20:00'),
            $this->waiting('b3', '09:00:00', '09:30:00'),
            $this->waiting('b4', '09:00:00', '10:00:00'),
        ]);

        self::assertSame(4, $result->sampleSize);
        self::assertSame(30.0, $result->averageMinutes);
        self::assertSame(25.0, $result->medianMinutes);
    }

    /**
     * Непарна кількість: 10, 20, 60 → середнє 30, медіана 20.
     */
    #[Test]
    public function computesMedianForOddSample(): void
    {
        $result = $this->calculator->calculate([
            $this->waiting('b1', '09:00:00', '09:10:00'),
            $this->waiting('b2', '09:00:00', '09:20:00'),
            $this->waiting('b3', '09:00:00', '10:00:00'),
        ]);

        self::assertSame(30.0, $result->averageMinutes);
        self::assertSame(20.0, $result->medianMinutes);
    }

    #[Test]
    public function bookingsWithoutBothEventsAreExcludedFromSample(): void
    {
        $result = $this->calculator->calculate([
            $this->waiting('b1', '09:00:00', '09:15:00'),
            // прибув, але розвантаження ще не почалося
            Fixtures::booking(bookingId: 'b2', status: BookingStatus::Arrived, arrivedAt: '2026-03-16 09:00:00'),
            // взагалі не прибув
            Fixtures::booking(bookingId: 'b3', status: BookingStatus::NoShow),
        ]);

        self::assertSame(1, $result->sampleSize);
        self::assertSame(15.0, $result->averageMinutes);
        self::assertSame(15.0, $result->medianMinutes);
    }

    #[Test]
    public function inconsistentPairWithUnloadingBeforeArrivalIsSkipped(): void
    {
        $result = $this->calculator->calculate([
            $this->waiting('broken', '09:30:00', '09:00:00'),
        ]);

        self::assertSame(0, $result->sampleSize);
        self::assertNull($result->averageMinutes);
        self::assertNull($result->medianMinutes);
        self::assertFalse($result->hasData());
    }

    #[Test]
    public function emptyDatasetReturnsNulls(): void
    {
        $result = $this->calculator->calculate([]);

        self::assertSame(0, $result->sampleSize);
        self::assertNull($result->averageMinutes);
        self::assertNull($result->medianMinutes);
    }

    #[Test]
    public function subMinutePrecisionIsPreserved(): void
    {
        $result = $this->calculator->calculate([
            $this->waiting('b1', '09:00:00', '09:00:30'),
        ]);

        self::assertSame(0.5, $result->averageMinutes);
    }

    private function waiting(string $id, string $arrived, string $unloading): \App\Domain\Fact\BookingFact
    {
        return Fixtures::booking(
            bookingId: $id,
            status: BookingStatus::Completed,
            arrivedAt: '2026-03-16 ' . $arrived,
            unloadingStartedAt: '2026-03-16 ' . $unloading,
        );
    }
}
