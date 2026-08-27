<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kpi;

use App\Domain\Booking\BookingStatus;
use App\Domain\Kpi\UnloadingDurationCalculator;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ANL-04: середнє і медіана інтервалу unloading → completed
 * та порівняння з розміром слоту.
 */
#[CoversClass(UnloadingDurationCalculator::class)]
final class UnloadingDurationCalculatorTest extends TestCase
{
    private UnloadingDurationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new UnloadingDurationCalculator();
    }

    /**
     * Розвантаження 20, 40 і 60 хв: середнє 40, медіана 40.
     */
    #[Test]
    public function computesAverageAndMedianUnloadingDuration(): void
    {
        $result = $this->calculator->calculate($this->referenceFacts());

        self::assertSame(3, $result->sampleSize);
        self::assertSame(40.0, $result->averageMinutes);
        self::assertSame(40.0, $result->medianMinutes);
    }

    /**
     * Розмір слоту в наборі — 30 хв, тому середнє розвантаження (40 хв)
     * перевищує слот: саме це порівняння вимагає ANL-04.
     */
    #[Test]
    public function comparesAverageUnloadingWithSlotSize(): void
    {
        $facts = $this->referenceFacts();

        self::assertSame(30.0, $this->calculator->averageSlotMinutes($facts));
        self::assertGreaterThan(
            $this->calculator->averageSlotMinutes($facts),
            $this->calculator->calculate($facts)->averageMinutes,
        );
    }

    #[Test]
    public function bookingsWithoutCompletionAreExcluded(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::booking(
                bookingId: 'u1',
                status: BookingStatus::Unloading,
                arrivedAt: '2026-03-16 08:00:00',
                unloadingStartedAt: '2026-03-16 08:10:00',
            ),
        ]);

        self::assertSame(0, $result->sampleSize);
        self::assertNull($result->averageMinutes);
    }

    #[Test]
    public function emptyDatasetReturnsNullsForBothMetrics(): void
    {
        $result = $this->calculator->calculate([]);

        self::assertNull($result->averageMinutes);
        self::assertNull($result->medianMinutes);
        self::assertNull($this->calculator->averageSlotMinutes([]));
    }

    /**
     * @return list<\App\Domain\Fact\BookingFact>
     */
    private function referenceFacts(): array
    {
        return [
            Fixtures::booking(
                bookingId: 'u1',
                slotStart: '2026-03-16 08:00:00',
                slotEnd: '2026-03-16 08:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 07:55:00',
                unloadingStartedAt: '2026-03-16 08:00:00',
                completedAt: '2026-03-16 08:20:00',
            ),
            Fixtures::booking(
                bookingId: 'u2',
                slotStart: '2026-03-16 09:00:00',
                slotEnd: '2026-03-16 09:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 08:55:00',
                unloadingStartedAt: '2026-03-16 09:00:00',
                completedAt: '2026-03-16 09:40:00',
            ),
            Fixtures::booking(
                bookingId: 'u3',
                slotStart: '2026-03-16 10:00:00',
                slotEnd: '2026-03-16 10:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 09:55:00',
                unloadingStartedAt: '2026-03-16 10:00:00',
                completedAt: '2026-03-16 11:00:00',
            ),
        ];
    }
}
