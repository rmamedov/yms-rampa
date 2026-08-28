<?php

declare(strict_types=1);

namespace App\Tests\Domain\Configuration;

use App\Domain\Configuration\CalendarException;
use App\Domain\Configuration\CalendarExceptionType;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\ReceivingWindow;
use App\Domain\Configuration\SlotSize;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\TimeInterval;
use App\Domain\Shared\Uuid;
use App\Domain\Shared\ValidationException;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Валідації конфігурації магазину: STC-10..STC-13, STC-20..STC-22, STC-30, STL-04, DATA-10.
 */
#[CoversClass(StoreConfiguration::class)]
final class StoreConfigurationTest extends TestCase
{
    /** STC-20 / DATA-10: розмір слоту — крок 5 хвилин у межах 5…120. */
    #[DataProvider('slotSizeProvider')]
    public function testSlotSizeStepAndRange(int $minutes, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(ValidationException::class);
        }

        $size = SlotSize::fromMinutes($minutes);

        if ($valid) {
            self::assertSame($minutes, $size->value);
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function slotSizeProvider(): iterable
    {
        // Колишній перелік лишається чинним…
        yield '15 хв' => [15, true];
        yield '20 хв' => [20, true];
        yield '30 хв' => [30, true];
        yield '60 хв' => [60, true];
        // …і поруч зʼявилися проміжні значення з кроком 5 хвилин.
        yield '5 хв — мінімум' => [5, true];
        yield '10 хв' => [10, true];
        yield '45 хв' => [45, true];
        yield '90 хв' => [90, true];
        yield '120 хв — максимум' => [120, true];
        // Не кратне кроку або поза межами — відхиляється.
        yield '7 хв не кратно 5' => [7, false];
        yield '33 хв не кратно 5' => [33, false];
        yield '0 хв поза межами' => [0, false];
        yield '125 хв поза межами' => [125, false];
    }

    /** STC-30: maxVehicleWeightTons — крок 0.5 у діапазоні 1.0–40.0. */
    #[DataProvider('weightProvider')]
    public function testMaxVehicleWeightRangeAndStep(float $tons, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(ValidationException::class);
        }

        $config = $this->config(maxWeight: $tons);

        if ($valid) {
            self::assertSame($tons, $config->maxVehicleWeightTons);
        }
    }

    /**
     * @return iterable<string, array{float, bool}>
     */
    public static function weightProvider(): iterable
    {
        yield 'нижня межа' => [1.0, true];
        yield 'верхня межа' => [40.0, true];
        yield 'крок 0.5' => [12.5, true];
        yield 'нижче межі' => [0.5, false];
        yield 'вище межі' => [40.5, false];
        yield 'крок 0.1 заборонено' => [10.1, false];
        yield 'крок 0.25 заборонено' => [10.25, false];
    }

    /** STC-11: інтервали одного дня не перетинаються. */
    public function testOverlappingIntervalsInOneDayAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('не можуть перетинатися');

        new ReceivingWindow(1, [
            new TimeInterval('06:00', '12:00'),
            new TimeInterval('11:00', '15:00'),
        ]);
    }

    public function testAdjacentIntervalsAreAllowed(): void
    {
        $window = new ReceivingWindow(1, [
            new TimeInterval('06:00', '12:00'),
            new TimeInterval('12:00', '18:00'),
        ]);

        self::assertCount(2, $window->intervals);
        self::assertSame(720, $window->totalMinutes());
    }

    /** STC-11: початок < кінець. */
    public function testIntervalStartMustPrecedeEnd(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('раніше за кінець');

        new TimeInterval('12:00', '06:00');
    }

    /** STC-11: крок часу — 5 хвилин. */
    #[DataProvider('timeStepProvider')]
    public function testFiveMinuteStep(string $time, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(ValidationException::class);
        }

        $minutes = TimeInterval::parse($time);

        if ($valid) {
            self::assertGreaterThanOrEqual(0, $minutes);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function timeStepProvider(): iterable
    {
        yield 'рівна година' => ['06:00', true];
        yield 'чверть' => ['06:15', true];
        yield 'не кратно 5' => ['06:07', false];
        yield 'не той формат' => ['6:00', false];
        yield 'година поза добою' => ['24:00', false];
    }

    /** STC-11: тривалість інтервалу ≥ розміру слоту. */
    public function testIntervalShorterThanSlotSizeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('коротший за розмір слоту');

        $this->config(
            windows: [new ReceivingWindow(1, [new TimeInterval('06:00', '06:30')])],
            slotSize: SlotSize::fromMinutes(60),
        );
    }

    /** STC-21: рампи ≥1, номер унікальний у межах магазину. */
    public function testAtLeastOneRampIsRequired(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('щонайменше одну рампу');

        $this->config(ramps: []);
    }

    public function testDuplicateRampNumbersAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('повторюється');

        $this->config(ramps: [new Ramp('r1', 1), new Ramp('r2', 1)]);
    }

    public function testDuplicateRampIdsAreRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->config(ramps: [new Ramp('r1', 1), new Ramp('r1', 2)]);
    }

    public function testRampNumberMustBePositive(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('≥ 1');

        new Ramp('r0', 0);
    }

    /** STC-22: вимкнена рампа не бере участі в генерації слотів. */
    public function testDisabledRampIsExcludedFromActiveRamps(): void
    {
        $config = BranchFactory::completeConfiguration();

        self::assertCount(3, $config->ramps);
        self::assertCount(2, $config->activeRamps());
        self::assertFalse($config->isRampActive('r3'));
        self::assertTrue($config->isRampActive('r1'));
    }

    /** STL-04: «Налаштовано» = вікна + слот + активна рампа + тоннаж. */
    public function testReadinessRequiresWindowsAndActiveRamps(): void
    {
        self::assertTrue(BranchFactory::completeConfiguration()->isComplete());

        $incomplete = BranchFactory::incompleteConfiguration();

        self::assertFalse($incomplete->isComplete());
        self::assertSame(['вікна прийому', 'активні рампи'], $incomplete->readiness()->missing);
    }

    public function testConfigurationWithoutActiveRampIsIncomplete(): void
    {
        $config = $this->config(ramps: [new Ramp('r1', 1, null, false)]);

        self::assertFalse($config->isComplete());
        self::assertSame(['активні рампи'], $config->readiness()->missing);
    }

    /** leadTimeMinutes 0–1440, дефолт 60. */
    public function testLeadTimeDefaultAndRange(): void
    {
        self::assertSame(60, $this->config()->leadTimeMinutes);
        self::assertSame(1440, $this->config(leadTime: 1440)->leadTimeMinutes);

        $this->expectException(ValidationException::class);
        $this->config(leadTime: 1441);
    }

    public function testNegativeLeadTimeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->config(leadTime: -1);
    }

    /** bookingHorizonDays 1–30, дефолт 14. */
    public function testBookingHorizonDefaultAndRange(): void
    {
        self::assertSame(14, $this->config()->bookingHorizonDays);

        $this->expectException(ValidationException::class);
        $this->config(horizon: 31);
    }

    public function testNoShowGraceAndHoldDefaults(): void
    {
        $config = $this->config();

        self::assertSame(30, $config->noShowGraceMinutes);
        self::assertSame(15, $config->holdMaxMinutes);
    }

    /** STC-12: виняток календаря має пріоритет над тижневим шаблоном. */
    public function testCalendarExceptionOverridesWeeklyTemplate(): void
    {
        // 2026-08-31 — понеділок, у шаблоні 06:00–12:00.
        $config = $this->config(exceptions: [
            new CalendarException('2026-08-31', CalendarExceptionType::Closed, 'Інвентаризація'),
            new CalendarException('2026-09-01', CalendarExceptionType::Custom, 'Скорочений день', [
                new TimeInterval('08:00', '10:00'),
            ]),
        ]);

        self::assertSame([], $config->intervalsForLocalDate('2026-08-31'));
        self::assertCount(1, $config->intervalsForLocalDate('2026-09-01'));
        self::assertSame('08:00', $config->intervalsForLocalDate('2026-09-01')[0]->from);
        // Дата без винятку — за тижневим шаблоном (понеділок 2026-09-07).
        self::assertSame('06:00', $config->intervalsForLocalDate('2026-09-07')[0]->from);
    }

    /** STC-12: причина винятку обовʼязкова. */
    public function testCalendarExceptionRequiresReason(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Причина');

        new CalendarException('2026-12-31', CalendarExceptionType::Closed, '   ');
    }

    public function testCustomCalendarExceptionRequiresIntervals(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('щонайменше один інтервал');

        new CalendarException('2026-12-31', CalendarExceptionType::Custom, 'Скорочений день');
    }

    /** STC-13: виняток не може бути в минулому і не далі 365 днів уперед. */
    public function testCalendarExceptionCannotBeInThePast(): void
    {
        $exception = new CalendarException('2026-08-01', CalendarExceptionType::Closed, 'Свято');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('не може бути в минулому');

        $exception->assertWithinAllowedRange('2026-08-27');
    }

    public function testCalendarExceptionCannotBeFurtherThan365Days(): void
    {
        $exception = new CalendarException('2027-09-01', CalendarExceptionType::Closed, 'Свято');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('365');

        $exception->assertWithinAllowedRange('2026-08-27');
    }

    public function testDuplicateCalendarExceptionDatesAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('більше одного винятку');

        $this->config(exceptions: [
            new CalendarException('2026-12-31', CalendarExceptionType::Closed, 'Свято'),
            new CalendarException('2026-12-31', CalendarExceptionType::Closed, 'Інвентаризація'),
        ]);
    }

    /** STC-42: перевірка потрапляння часу резерву у вікно прийому. */
    public function testIsWithinReceivingWindow(): void
    {
        $config = BranchFactory::completeConfiguration();

        self::assertTrue($config->isWithinReceivingWindow(1, '06:00'));
        self::assertTrue($config->isWithinReceivingWindow(1, '11:30'));
        self::assertFalse($config->isWithinReceivingWindow(1, '12:00'), 'кінець вікна вже поза ним');
        self::assertFalse($config->isWithinReceivingWindow(1, '14:00'));
        self::assertTrue($config->isWithinReceivingWindow(2, '14:00'));
        self::assertFalse($config->isWithinReceivingWindow(6, '08:00'), 'субота — без прийому');
    }

    public function testDuplicateDayOfWeekIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('задано двічі');

        $this->config(windows: [
            new ReceivingWindow(1, [new TimeInterval('06:00', '12:00')]),
            new ReceivingWindow(1, [new TimeInterval('14:00', '18:00')]),
        ]);
    }

    public function testDayOfWeekOutsideOneToSevenIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('1–7');

        new ReceivingWindow(8, []);
    }

    /**
     * @param list<ReceivingWindow>   $windows
     * @param list<Ramp>              $ramps
     * @param list<CalendarException> $exceptions
     */
    private function config(
        ?array $windows = null,
        ?SlotSize $slotSize = null,
        ?array $ramps = null,
        float $maxWeight = 10.0,
        int $leadTime = StoreConfiguration::LEAD_TIME_DEFAULT,
        int $horizon = StoreConfiguration::HORIZON_DEFAULT_DAYS,
        array $exceptions = [],
    ): StoreConfiguration {
        return new StoreConfiguration(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            version: 1,
            effectiveFrom: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            receivingWindows: $windows ?? [new ReceivingWindow(1, [new TimeInterval('06:00', '12:00')])],
            slotSize: $slotSize ?? SlotSize::fromMinutes(30),
            ramps: $ramps ?? [new Ramp('r1', 1, 'Рампа 1')],
            maxVehicleWeightTons: $maxWeight,
            leadTimeMinutes: $leadTime,
            bookingHorizonDays: $horizon,
            calendarExceptions: $exceptions,
        );
    }
}
