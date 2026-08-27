<?php

declare(strict_types=1);

namespace App\Tests\Domain\Slot;

use App\Domain\Slot\CalendarException;
use App\Domain\Slot\DateOutOfHorizonException;
use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\ReservedSlotRule;
use App\Domain\Slot\SlotBlock;
use App\Domain\Slot\SlotGridGenerator;
use App\Domain\Slot\SlotKey;
use App\Domain\Slot\SlotOverlays;
use App\Domain\Slot\SlotState;
use App\Domain\Slot\StoreConfig;
use App\Domain\Slot\TimeInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SlotGridGeneratorTest extends TestCase
{
    private const string STORE_ID = 'store-1998';
    /** Понеділок. */
    private const string DATE = '2026-09-07';

    private SlotGridGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SlotGridGenerator();
    }

    #[Test]
    public function gridSizeMatchesAcceptanceCriterion(): void
    {
        // GRID-06: вікно 08:00–14:00, слот 30 хв, 2 рампи → рівно 24 слоти.
        $grid = $this->generator->generate(
            $this->config(),
            self::DATE,
            $this->nowBefore(),
            'supplier-a',
        );

        self::assertCount(24, $grid->slots);
        self::assertCount(24, $grid->selectableSlots());
    }

    #[Test]
    public function blockingOneRampRemovesExactlyTheCoveredSlots(): void
    {
        // GRID-06: блокування рампи 2 на 10:00–12:00 прибирає рівно 4 слоти.
        $overlays = new SlotOverlays(blocks: [
            new SlotBlock(
                self::STORE_ID,
                'ramp-2',
                $this->kyiv('10:00'),
                $this->kyiv('12:00'),
                'Рампа на ремонті',
            ),
        ]);

        $grid = $this->generator->generate(
            $this->config(),
            self::DATE,
            $this->nowBefore(),
            'supplier-a',
            $overlays,
        );

        self::assertCount(24, $grid->slots, 'Заблоковані слоти лишаються в сітці, змінюється лише стан');
        self::assertCount(20, $grid->selectableSlots());
        self::assertSame(4, $grid->countInState(SlotState::Blocked));

        foreach ($grid->slotsInState(SlotState::Blocked) as $slot) {
            self::assertSame('ramp-2', $slot->key->rampId);
            self::assertSame('Рампа на ремонті', $slot->blockReason);
        }
    }

    #[Test]
    public function blockWithoutRampCoversEveryRamp(): void
    {
        $overlays = new SlotOverlays(blocks: [
            new SlotBlock(self::STORE_ID, null, $this->kyiv('08:00'), $this->kyiv('09:00')),
        ]);

        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a', $overlays);

        self::assertSame(4, $grid->countInState(SlotState::Blocked));
    }

    #[Test]
    public function leadTimeMarksImminentSlotsAsPast(): void
    {
        // GRID-02: слот стає past, якщо slotStart < now + leadTimeMinutes (дефолт 60 хв).
        $now = $this->kyiv('09:30');

        $grid = $this->generator->generate($this->config(), self::DATE, $now, 'supplier-a');

        // Доступні лише слоти від 10:30 включно: 10:30…13:30 = 7 стартів × 2 рампи.
        self::assertCount(14, $grid->selectableSlots());
        self::assertSame(10, $grid->countInState(SlotState::Past));

        foreach ($grid->selectableSlots() as $slot) {
            self::assertGreaterThanOrEqual('10:30', $slot->localStartTime());
        }
    }

    #[Test]
    public function zeroLeadTimeAllowsBookingUpToSlotStart(): void
    {
        $config = $this->config(leadTimeMinutes: 0);
        $grid = $this->generator->generate($config, self::DATE, $this->kyiv('09:30'), 'supplier-a');

        self::assertSame(6, $grid->countInState(SlotState::Past), 'Минулими є лише слоти до 09:30');
    }

    #[Test]
    public function dateBeyondHorizonIsRejected(): void
    {
        $this->expectException(DateOutOfHorizonException::class);
        $this->expectExceptionMessage('не далі ніж на 14 днів');

        $this->generator->generate($this->config(), '2026-09-30', $this->nowBefore(), 'supplier-a');
    }

    #[Test]
    public function lastDayOfHorizonIsStillAllowed(): void
    {
        // now = 2026-09-07, горизонт 14 днів → 2026-09-21 включно.
        $grid = $this->generator->generate($this->config(), '2026-09-21', $this->nowBefore(), 'supplier-a');

        self::assertNotEmpty($grid->slots);
    }

    #[Test]
    public function pastDatesRemainViewableForStore(): void
    {
        $grid = $this->generator->generate($this->config(), '2026-08-31', $this->nowBefore(), null);

        self::assertCount(24, $grid->slots);
        self::assertSame(24, $grid->countInState(SlotState::Past));
    }

    #[Test]
    public function reservedSlotIsBookableOnlyByItsSupplier(): void
    {
        $overlays = new SlotOverlays(reservedRules: [
            new ReservedSlotRule(
                supplierId: 'supplier-critical',
                rampId: 'ramp-1',
                slotStartTime: '09:00',
                dayOfWeek: 1,
            ),
        ]);

        $owner = $this->generator->generate(
            $this->config(), self::DATE, $this->nowBefore(), 'supplier-critical', $overlays,
        );
        $ownerSlot = $this->findSlot($owner->slots, 'ramp-1', '09:00');

        self::assertSame(SlotState::Available, $ownerSlot->state);
        self::assertTrue($ownerSlot->reservedForViewer);
        self::assertTrue($ownerSlot->isSelectable());

        $stranger = $this->generator->generate(
            $this->config(), self::DATE, $this->nowBefore(), 'supplier-a', $overlays,
        );
        $strangerSlot = $this->findSlot($stranger->slots, 'ramp-1', '09:00');

        self::assertSame(SlotState::Reserved, $strangerSlot->state);
        self::assertFalse($strangerSlot->isSelectable());
        self::assertFalse($strangerSlot->reservedForViewer);
        self::assertNull(
            $strangerSlot->reservedForSupplierId,
            'GRID-04: чужому постачальнику не розкривається, за ким закріплено резерв',
        );
    }

    #[Test]
    public function staffSeesWhichSupplierHoldsTheReservation(): void
    {
        $overlays = new SlotOverlays(reservedRules: [
            new ReservedSlotRule('supplier-critical', 'ramp-1', '09:00', dayOfWeek: 1),
        ]);

        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), null, $overlays);
        $slot = $this->findSlot($grid->slots, 'ramp-1', '09:00');

        self::assertSame(SlotState::Reserved, $slot->state);
        self::assertSame('supplier-critical', $slot->reservedForSupplierId);
    }

    #[Test]
    public function reservationAppliesOnlyToItsRampAndTime(): void
    {
        $overlays = new SlotOverlays(reservedRules: [
            new ReservedSlotRule('supplier-critical', 'ramp-1', '09:00', dayOfWeek: 1),
        ]);

        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a', $overlays);

        self::assertSame(1, $grid->countInState(SlotState::Reserved));
        self::assertSame(SlotState::Available, $this->findSlot($grid->slots, 'ramp-2', '09:00')->state);
        self::assertSame(SlotState::Available, $this->findSlot($grid->slots, 'ramp-1', '09:30')->state);
    }

    #[Test]
    public function oneOffReservationAppliesOnlyToItsDate(): void
    {
        $overlays = new SlotOverlays(reservedRules: [
            new ReservedSlotRule('supplier-critical', 'ramp-1', '09:00', date: self::DATE),
        ]);

        $onDate = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a', $overlays);
        self::assertSame(1, $onDate->countInState(SlotState::Reserved));

        // Наступний понеділок — той самий день тижня, але правило разове.
        $nextWeek = $this->generator->generate($this->config(), '2026-09-14', $this->nowBefore(), 'supplier-a', $overlays);
        self::assertSame(0, $nextWeek->countInState(SlotState::Reserved));
    }

    #[Test]
    public function expiredReservationRuleIsIgnored(): void
    {
        $overlays = new SlotOverlays(reservedRules: [
            new ReservedSlotRule(
                'supplier-critical', 'ramp-1', '09:00',
                dayOfWeek: 1, validFrom: '2026-01-01', validTo: '2026-08-31',
            ),
        ]);

        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a', $overlays);

        self::assertSame(0, $grid->countInState(SlotState::Reserved));
    }

    #[Test]
    public function bookedAndHeldSlotsAreNotSelectable(): void
    {
        $overlays = new SlotOverlays(
            bookedKeys: [$this->key('ramp-1', '09:00')->toString()],
            heldKeys: [$this->key('ramp-2', '09:00')->toString()],
        );

        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a', $overlays);

        self::assertSame(SlotState::Booked, $this->findSlot($grid->slots, 'ramp-1', '09:00')->state);
        self::assertSame(SlotState::Held, $this->findSlot($grid->slots, 'ramp-2', '09:00')->state);
        self::assertCount(22, $grid->selectableSlots());
    }

    /**
     * SLOT-03: пріоритет станів past → blocked → booked → held → reserved → available.
     */
    #[Test]
    public function statePriorityIsRespectedWhenOverlaysCollide(): void
    {
        $overlays = new SlotOverlays(
            blocks: [new SlotBlock(self::STORE_ID, 'ramp-1', $this->kyiv('09:00'), $this->kyiv('09:30'))],
            reservedRules: [new ReservedSlotRule('supplier-a', 'ramp-1', '09:00', dayOfWeek: 1)],
            bookedKeys: [$this->key('ramp-1', '09:00')->toString()],
            heldKeys: [$this->key('ramp-1', '09:00')->toString()],
        );

        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a', $overlays);

        self::assertSame(
            SlotState::Blocked,
            $this->findSlot($grid->slots, 'ramp-1', '09:00')->state,
            'Блокування має вищий пріоритет за бронювання, холд і резерв',
        );

        // Той самий набір, але слот уже в минулому: past перекриває все.
        $gridLater = $this->generator->generate($this->config(), self::DATE, $this->kyiv('13:00'), 'supplier-a', $overlays);

        self::assertSame(SlotState::Past, $this->findSlot($gridLater->slots, 'ramp-1', '09:00')->state);
    }

    #[Test]
    public function inactiveRampProducesNoSlots(): void
    {
        $config = new StoreConfig(
            storeId: self::STORE_ID,
            receivingWindows: [new ReceivingWindow(1, [new TimeInterval('08:00', '14:00')])],
            slotSizeMinutes: 30,
            ramps: [new Ramp('ramp-1', 'Рампа 1'), new Ramp('ramp-2', 'Рампа 2', active: false)],
            maxVehicleWeightTons: 20.0,
        );

        $grid = $this->generator->generate($config, self::DATE, $this->nowBefore(), 'supplier-a');

        self::assertCount(12, $grid->slots);
        foreach ($grid->slots as $slot) {
            self::assertSame('ramp-1', $slot->key->rampId);
        }
    }

    #[Test]
    public function dayWithoutReceivingWindowHasNoSlots(): void
    {
        // Неділя — вікна прийому не задано.
        $grid = $this->generator->generate($this->config(), '2026-09-13', $this->nowBefore(), 'supplier-a');

        self::assertSame([], $grid->slots);
    }

    #[Test]
    public function closedCalendarExceptionOverridesWeeklyWindow(): void
    {
        $config = $this->config(calendarExceptions: [
            new CalendarException(self::DATE, closed: true, reason: 'Інвентаризація'),
        ]);

        $grid = $this->generator->generate($config, self::DATE, $this->nowBefore(), 'supplier-a');

        self::assertSame([], $grid->slots);
    }

    #[Test]
    public function calendarExceptionCanShortenTheDay(): void
    {
        $config = $this->config(calendarExceptions: [
            new CalendarException(self::DATE, closed: false, intervals: [new TimeInterval('08:00', '10:00')]),
        ]);

        $grid = $this->generator->generate($config, self::DATE, $this->nowBefore(), 'supplier-a');

        self::assertCount(8, $grid->slots, '4 слоти по 30 хв × 2 рампи');
    }

    #[Test]
    public function severalIntervalsPerDayAreSlicedIndependently(): void
    {
        $config = $this->config(intervals: [
            new TimeInterval('08:00', '10:00'),
            new TimeInterval('14:00', '16:00'),
        ]);

        $grid = $this->generator->generate($config, self::DATE, $this->nowBefore(), 'supplier-a');

        self::assertCount(16, $grid->slots);
        self::assertNull($this->findSlotOrNull($grid->slots, 'ramp-1', '12:00'), 'Перерва слотів не породжує');
    }

    #[Test]
    public function incompleteTailOfIntervalIsNotSliced(): void
    {
        $config = $this->config(intervals: [new TimeInterval('08:00', '09:20')]);

        $grid = $this->generator->generate($config, self::DATE, $this->nowBefore(), 'supplier-a');

        self::assertCount(4, $grid->slots, 'Хвіст 09:00–09:20 коротший за слот і слотом не стає');
    }

    #[Test]
    public function springForwardHourProducesNoSlots(): void
    {
        // 29.03.2026 Київ переходить на літній час: локальної 03:00 не існує.
        $config = $this->config(
            intervals: [new TimeInterval('00:00', '06:00')],
            slotSizeMinutes: 60,
            dayOfWeek: 7,
        );

        $grid = $this->generator->generate(
            $config,
            '2026-03-29',
            new DateTimeImmutable('2026-03-20 00:00:00', new DateTimeZone('UTC')),
            'supplier-a',
        );

        $times = array_map(
            static fn ($slot) => $slot->localStartTime(),
            array_values(array_filter($grid->slots, static fn ($s) => 'ramp-1' === $s->key->rampId)),
        );

        self::assertSame(['00:00', '01:00', '02:00', '04:00', '05:00'], $times);
        self::assertNotContains('03:00', $times);
    }

    #[Test]
    public function autumnFallBackKeepsGridDeterministic(): void
    {
        // 25.10.2026 година 03:00 у Києві повторюється; сітка має лишатися однозначною.
        $config = $this->config(
            intervals: [new TimeInterval('00:00', '06:00')],
            slotSizeMinutes: 60,
            dayOfWeek: 7,
        );
        $now = new DateTimeImmutable('2026-10-20 00:00:00', new DateTimeZone('UTC'));

        $first = $this->generator->generate($config, '2026-10-25', $now, 'supplier-a');
        $second = $this->generator->generate($config, '2026-10-25', $now, 'supplier-a');

        self::assertSame($first->toArray(), $second->toArray(), 'SLOT-04: алгоритм детермінований');

        $starts = array_map(
            static fn ($slot) => $slot->key->slotStart->format('H:i'),
            array_values(array_filter($first->slots, static fn ($s) => 'ramp-1' === $s->key->rampId)),
        );

        self::assertSame(\count($starts), \count(array_unique($starts)), 'Жоден слот не дублюється за UTC');
    }

    #[Test]
    public function gridExposesParametersNeededByClient(): void
    {
        // GRID-05
        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a');
        $payload = $grid->toArray();

        self::assertSame(20.0, $payload['maxVehicleWeightTons']);
        self::assertSame(30, $payload['slotSizeMinutes']);
        self::assertSame(60, $payload['leadTimeMinutes']);
        self::assertArrayHasKey('now', $payload);
    }

    #[Test]
    public function slotsAreOrderedByTimeThenRamp(): void
    {
        $grid = $this->generator->generate($this->config(), self::DATE, $this->nowBefore(), 'supplier-a');

        $previous = null;
        foreach ($grid->slots as $slot) {
            $current = [$slot->key->slotStart->getTimestamp(), $slot->key->rampId];
            if (null !== $previous) {
                self::assertLessThanOrEqual(0, $previous <=> $current);
            }
            $previous = $current;
        }
    }

    /**
     * @param list<TimeInterval>       $intervals
     * @param list<CalendarException>  $calendarExceptions
     */
    private function config(
        array $intervals = [],
        int $slotSizeMinutes = 30,
        int $leadTimeMinutes = 60,
        array $calendarExceptions = [],
        int $dayOfWeek = 1,
    ): StoreConfig {
        return new StoreConfig(
            storeId: self::STORE_ID,
            receivingWindows: [
                new ReceivingWindow($dayOfWeek, [] === $intervals ? [new TimeInterval('08:00', '14:00')] : $intervals),
            ],
            slotSizeMinutes: $slotSizeMinutes,
            ramps: [new Ramp('ramp-1', 'Рампа 1'), new Ramp('ramp-2', 'Рампа 2')],
            maxVehicleWeightTons: 20.0,
            leadTimeMinutes: $leadTimeMinutes,
            bookingHorizonDays: 14,
            calendarExceptions: $calendarExceptions,
        );
    }

    /** Момент, з якого вся сітка дня попереду: 07:00 за Києвом. */
    private function nowBefore(): DateTimeImmutable
    {
        return $this->kyiv('06:30');
    }

    private function kyiv(string $time, string $date = self::DATE): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' '.$time, new DateTimeZone(StoreConfig::TIMEZONE));
    }

    private function key(string $rampId, string $localTime): SlotKey
    {
        return new SlotKey(self::STORE_ID, $rampId, $this->kyiv($localTime));
    }

    /** @param list<\App\Domain\Slot\Slot> $slots */
    private function findSlot(array $slots, string $rampId, string $localTime): \App\Domain\Slot\Slot
    {
        $slot = $this->findSlotOrNull($slots, $rampId, $localTime);
        self::assertNotNull($slot, \sprintf('Слот %s о %s не знайдено', $rampId, $localTime));

        return $slot;
    }

    /** @param list<\App\Domain\Slot\Slot> $slots */
    private function findSlotOrNull(array $slots, string $rampId, string $localTime): ?\App\Domain\Slot\Slot
    {
        foreach ($slots as $slot) {
            if ($slot->key->rampId === $rampId && $slot->localStartTime() === $localTime) {
                return $slot;
            }
        }

        return null;
    }
}
