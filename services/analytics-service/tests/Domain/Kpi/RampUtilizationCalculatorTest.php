<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kpi;

use App\Domain\Kpi\RampUtilizationCalculator;
use App\Domain\Slot\SlotFact;
use App\Domain\Slot\SlotState;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KPI-01: booked_minutes / available_minutes × 100%, слото-хвилини,
 * blocked і past виключаються зі знаменника.
 */
#[CoversClass(RampUtilizationCalculator::class)]
final class RampUtilizationCalculatorTest extends TestCase
{
    private RampUtilizationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RampUtilizationCalculator();
    }

    /**
     * Еталонний набір, ручний розрахунок:
     * у знаменник входять 2 booked + 1 available + 1 reserved + 1 held = 5 × 30 хв = 150 хв;
     * blocked і past (по 30 хв) виключені; чисельник = 2 × 30 = 60 хв.
     * 60 / 150 × 100 = 40%.
     */
    #[Test]
    public function computesReferenceDatasetExactly(): void
    {
        $result = $this->calculator->calculate($this->referenceSlots());

        self::assertSame(60.0, $result->bookedMinutes);
        self::assertSame(150.0, $result->availableMinutes);
        self::assertSame(40.0, $result->percent);
        self::assertSame(5, $result->slotsCounted);
        self::assertTrue($result->hasData());
    }

    #[Test]
    public function excludesBlockedAndPastFromDenominator(): void
    {
        $withoutExcluded = $this->calculator->calculate(array_filter(
            $this->referenceSlots(),
            static fn (SlotFact $slot): bool => !in_array($slot->state, [SlotState::Blocked, SlotState::Past], true),
        ));

        // Видалення blocked і past не змінює жодного числа — вони й так не рахувалися.
        self::assertSame(150.0, $withoutExcluded->availableMinutes);
        self::assertSame(40.0, $withoutExcluded->percent);
    }

    /**
     * Метрика вимірюється у ХВИЛИНАХ, а не в кількості слотів:
     * 1 заброньований слот на 45 хв і 1 вільний на 15 хв дають 75%, а не 50%.
     */
    #[Test]
    public function countsSlotMinutesNotSlotCount(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::slot('s1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:45:00'),
            Fixtures::slot('s2', SlotState::Available, '2026-03-16 08:45:00', '2026-03-16 09:00:00'),
        ]);

        self::assertSame(45.0, $result->bookedMinutes);
        self::assertSame(60.0, $result->availableMinutes);
        self::assertSame(75.0, $result->percent);
    }

    #[Test]
    public function heldAndReservedSlotsAreNotBookedMinutes(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::slot('s1', SlotState::Held, '2026-03-16 08:00:00', '2026-03-16 08:30:00'),
            Fixtures::slot('s2', SlotState::Reserved, '2026-03-16 08:30:00', '2026-03-16 09:00:00'),
        ]);

        self::assertSame(0.0, $result->bookedMinutes);
        self::assertSame(60.0, $result->availableMinutes);
        self::assertSame(0.0, $result->percent);
    }

    #[Test]
    public function emptyDatasetReturnsNullPercentInsteadOfDivisionByZero(): void
    {
        $result = $this->calculator->calculate([]);

        self::assertNull($result->percent);
        self::assertFalse($result->hasData());
        self::assertSame(0.0, $result->availableMinutes);
        self::assertSame(0, $result->slotsCounted);
    }

    #[Test]
    public function onlyBlockedAndPastSlotsGiveZeroDenominatorAndNullPercent(): void
    {
        $result = $this->calculator->calculate([
            Fixtures::slot('s1', SlotState::Blocked, '2026-03-16 08:00:00', '2026-03-16 08:30:00'),
            Fixtures::slot('s2', SlotState::Past, '2026-03-16 08:30:00', '2026-03-16 09:00:00'),
        ]);

        self::assertNull($result->percent);
        self::assertSame(0.0, $result->availableMinutes);
        self::assertSame(0, $result->slotsCounted);
    }

    /**
     * ANL-01: розріз за рампами. Ручний розрахунок:
     * ramp-1 — 30 booked зі 60 доступних = 50%; ramp-2 — 60 booked зі 60 = 100%.
     */
    #[Test]
    public function groupsUtilizationByRamp(): void
    {
        $slots = [
            Fixtures::slot('a1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00', rampId: 'ramp-1'),
            Fixtures::slot('a2', SlotState::Available, '2026-03-16 08:30:00', '2026-03-16 09:00:00', rampId: 'ramp-1'),
            Fixtures::slot('b1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00', rampId: 'ramp-2'),
            Fixtures::slot('b2', SlotState::Booked, '2026-03-16 08:30:00', '2026-03-16 09:00:00', rampId: 'ramp-2'),
            Fixtures::slot('b3', SlotState::Blocked, '2026-03-16 09:00:00', '2026-03-16 09:30:00', rampId: 'ramp-2'),
        ];

        $grouped = $this->calculator->calculateGrouped(
            $slots,
            static fn (SlotFact $slot): string => $slot->rampId,
        );

        self::assertSame(['ramp-1', 'ramp-2'], array_keys($grouped));
        self::assertSame(50.0, $grouped['ramp-1']->percent);
        self::assertSame(100.0, $grouped['ramp-2']->percent);
        self::assertSame(60.0, $grouped['ramp-2']->availableMinutes);
    }

    /**
     * @return list<SlotFact>
     */
    private function referenceSlots(): array
    {
        return [
            Fixtures::slot('s1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00'),
            Fixtures::slot('s2', SlotState::Booked, '2026-03-16 08:30:00', '2026-03-16 09:00:00'),
            Fixtures::slot('s3', SlotState::Available, '2026-03-16 09:00:00', '2026-03-16 09:30:00'),
            Fixtures::slot('s4', SlotState::Blocked, '2026-03-16 09:30:00', '2026-03-16 10:00:00'),
            Fixtures::slot('s5', SlotState::Past, '2026-03-16 10:00:00', '2026-03-16 10:30:00'),
            Fixtures::slot('s6', SlotState::Reserved, '2026-03-16 10:30:00', '2026-03-16 11:00:00'),
            Fixtures::slot('s7', SlotState::Held, '2026-03-16 11:00:00', '2026-03-16 11:30:00'),
        ];
    }
}
