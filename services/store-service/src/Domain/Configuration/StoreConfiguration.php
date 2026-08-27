<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\Timezone;
use App\Domain\Shared\ValidationException;

/**
 * Версіонована конфігурація магазину (10.2.2, DATA-09).
 *
 * Кожна зміна створює НОВИЙ документ з власним effectiveFrom; чинна версія —
 * та, що має максимальний effectiveFrom ≤ now. UPDATE існуючої версії заборонено,
 * тому клас незмінний (readonly).
 */
final readonly class StoreConfiguration
{
    public const float MIN_WEIGHT_TONS = 1.0;
    public const float MAX_WEIGHT_TONS = 40.0;
    public const float WEIGHT_STEP_TONS = 0.5;

    public const int LEAD_TIME_MIN = 0;
    public const int LEAD_TIME_MAX = 1440;
    public const int LEAD_TIME_DEFAULT = 60;

    public const int HORIZON_MIN_DAYS = 1;
    public const int HORIZON_MAX_DAYS = 30;
    public const int HORIZON_DEFAULT_DAYS = 14;

    public const int NO_SHOW_GRACE_DEFAULT = 30;
    public const int NO_SHOW_GRACE_MAX = 240;

    public const int HOLD_MAX_DEFAULT = 15;
    public const int HOLD_MAX_LIMIT = 60;

    public const int SCHEMA_VERSION = 1;

    /** @var array<int, ReceivingWindow> ключ — dayOfWeek */
    public array $receivingWindows;

    /** @var list<Ramp> */
    public array $ramps;

    /** @var array<string, CalendarException> ключ — дата YYYY-MM-DD */
    public array $calendarExceptions;

    /**
     * @param list<ReceivingWindow>  $receivingWindows
     * @param list<Ramp>             $ramps
     * @param list<CalendarException> $calendarExceptions
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public int $version,
        public \DateTimeImmutable $effectiveFrom,
        array $receivingWindows,
        public SlotSize $slotSize,
        array $ramps,
        public float $maxVehicleWeightTons,
        public int $leadTimeMinutes = self::LEAD_TIME_DEFAULT,
        public int $bookingHorizonDays = self::HORIZON_DEFAULT_DAYS,
        public int $noShowGraceMinutes = self::NO_SHOW_GRACE_DEFAULT,
        public int $holdMaxMinutes = self::HOLD_MAX_DEFAULT,
        array $calendarExceptions = [],
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $archivedAt = null,
    ) {
        if ($version < 1) {
            throw ValidationException::config('Версія конфігурації має бути ≥ 1', ['version' => 'Мінімум 1']);
        }

        if ('' === trim($storeId)) {
            throw ValidationException::config('Не вказано магазин конфігурації', ['storeId' => 'Обовʼязкове поле']);
        }

        $this->receivingWindows = $this->buildWindows($receivingWindows, $slotSize);
        $this->ramps = self::buildRamps($ramps);
        $this->calendarExceptions = self::buildExceptions($calendarExceptions, $slotSize);

        self::assertWeight($maxVehicleWeightTons);
        self::assertRange($leadTimeMinutes, self::LEAD_TIME_MIN, self::LEAD_TIME_MAX, 'leadTimeMinutes', 'Час до слота (хв)');
        self::assertRange($bookingHorizonDays, self::HORIZON_MIN_DAYS, self::HORIZON_MAX_DAYS, 'bookingHorizonDays', 'Горизонт бронювання (днів)');
        self::assertRange($noShowGraceMinutes, 0, self::NO_SHOW_GRACE_MAX, 'noShowGraceMinutes', 'Пільговий час до no-show (хв)');
        self::assertRange($holdMaxMinutes, 1, self::HOLD_MAX_LIMIT, 'holdMaxMinutes', 'Максимальний холд (хв)');
    }

    /**
     * Ознака «Налаштовано» за STL-04. Розмір слоту і maxVehicleWeightTons гарантовані
     * типами конструктора, тож бракувати може лише вікно прийому або активна рампа.
     */
    public function readiness(): ConfigurationReadiness
    {
        $missing = [];

        if (0 === $this->totalReceivingMinutes()) {
            $missing[] = 'вікна прийому';
        }

        if ([] === $this->activeRamps()) {
            $missing[] = 'активні рампи';
        }

        return ConfigurationReadiness::of($missing);
    }

    public function isComplete(): bool
    {
        return $this->readiness()->complete;
    }

    /** @return list<Ramp> */
    public function activeRamps(): array
    {
        return array_values(array_filter($this->ramps, static fn (Ramp $r): bool => $r->active));
    }

    public function ramp(string $rampId): ?Ramp
    {
        foreach ($this->ramps as $ramp) {
            if ($ramp->rampId === $rampId) {
                return $ramp;
            }
        }

        return null;
    }

    public function isRampActive(string $rampId): bool
    {
        return $this->ramp($rampId)?->active ?? false;
    }

    public function windowFor(int $dayOfWeek): ?ReceivingWindow
    {
        return $this->receivingWindows[$dayOfWeek] ?? null;
    }

    /**
     * Чинні інтервали прийому на конкретну локальну дату магазину.
     * Виняток календаря має пріоритет над тижневим шаблоном (STC-12).
     *
     * @return list<TimeInterval>
     */
    public function intervalsForLocalDate(string $date): array
    {
        $exception = $this->calendarExceptions[$date] ?? null;

        if ($exception instanceof CalendarException) {
            return $exception->isClosed() ? [] : $exception->intervals;
        }

        $dayOfWeek = (int) (new \DateTimeImmutable($date, Timezone::storeLocal()))->format('N');

        return $this->windowFor($dayOfWeek)?->intervals ?? [];
    }

    /**
     * Чи потрапляє час початку слота в якесь вікно прийому дня тижня (STC-42).
     */
    public function isWithinReceivingWindow(int $dayOfWeek, string $slotStartTime): bool
    {
        $minutes = TimeInterval::parse($slotStartTime, 'slotStartTime');

        foreach ($this->windowFor($dayOfWeek)?->intervals ?? [] as $interval) {
            if ($interval->containsStart($minutes)) {
                return true;
            }
        }

        return false;
    }

    public function totalReceivingMinutes(): int
    {
        $total = 0;

        foreach ($this->receivingWindows as $window) {
            $total += $window->totalMinutes();
        }

        return $total;
    }

    public function isEffectiveAt(\DateTimeImmutable $moment): bool
    {
        return $this->effectiveFrom <= $moment && null === $this->archivedAt;
    }

    /**
     * @param list<ReceivingWindow> $windows
     *
     * @return array<int, ReceivingWindow>
     */
    private function buildWindows(array $windows, SlotSize $slotSize): array
    {
        $indexed = [];

        foreach ($windows as $window) {
            if (isset($indexed[$window->dayOfWeek])) {
                throw ValidationException::config(
                    \sprintf('День тижня %d задано двічі', $window->dayOfWeek),
                    ['receivingWindows' => 'День тижня задано двічі'],
                );
            }

            self::assertIntervalsFitSlot($window->intervals, $slotSize);
            $indexed[$window->dayOfWeek] = $window;
        }

        ksort($indexed);

        return $indexed;
    }

    /**
     * STC-11: тривалість інтервалу має бути не меншою за розмір слоту,
     * інакше в інтервалі не згенерується жодного слота (STC-23).
     *
     * @param list<TimeInterval> $intervals
     */
    private static function assertIntervalsFitSlot(array $intervals, SlotSize $slotSize): void
    {
        foreach ($intervals as $interval) {
            if ($interval->durationMinutes() < $slotSize->value) {
                throw ValidationException::config(
                    \sprintf(
                        'Інтервал %s–%s коротший за розмір слоту (%d хв)',
                        $interval->from,
                        $interval->to,
                        $slotSize->value,
                    ),
                    ['intervals' => \sprintf('Інтервал має бути не коротшим за %d хв', $slotSize->value)],
                );
            }
        }
    }

    /**
     * @param list<Ramp> $ramps
     *
     * @return list<Ramp>
     */
    private static function buildRamps(array $ramps): array
    {
        if ([] === $ramps) {
            throw ValidationException::config(
                'Магазин повинен мати щонайменше одну рампу',
                ['ramps' => 'Додайте щонайменше одну рампу'],
            );
        }

        $ids = [];
        $numbers = [];

        foreach ($ramps as $ramp) {
            if (isset($ids[$ramp->rampId])) {
                throw ValidationException::config(
                    \sprintf('Ідентифікатор рампи «%s» повторюється', $ramp->rampId),
                    ['ramps' => 'Ідентифікатори рамп мають бути унікальні'],
                );
            }

            if (isset($numbers[$ramp->number])) {
                throw ValidationException::config(
                    \sprintf('Номер рампи %d повторюється в межах магазину', $ramp->number),
                    ['ramps' => 'Номери рамп мають бути унікальні в межах магазину'],
                );
            }

            $ids[$ramp->rampId] = true;
            $numbers[$ramp->number] = true;
        }

        return array_values($ramps);
    }

    /**
     * @param list<CalendarException> $exceptions
     *
     * @return array<string, CalendarException>
     */
    private static function buildExceptions(array $exceptions, SlotSize $slotSize): array
    {
        $indexed = [];

        foreach ($exceptions as $exception) {
            if (isset($indexed[$exception->date])) {
                throw ValidationException::config(
                    \sprintf('Для дати %s задано більше одного винятку', $exception->date),
                    ['calendarExceptions' => 'Для однієї дати може бути лише один виняток'],
                );
            }

            self::assertIntervalsFitSlot($exception->intervals, $slotSize);
            $indexed[$exception->date] = $exception;
        }

        ksort($indexed);

        return $indexed;
    }

    /** STC-30: число з кроком 0.5 у діапазоні 1.0–40.0. */
    private static function assertWeight(float $tons): void
    {
        if ($tons < self::MIN_WEIGHT_TONS || $tons > self::MAX_WEIGHT_TONS) {
            throw ValidationException::config(
                \sprintf(
                    'Максимальна маса авто має бути в межах %.1f–%.1f т',
                    self::MIN_WEIGHT_TONS,
                    self::MAX_WEIGHT_TONS,
                ),
                ['maxVehicleWeightTons' => 'Допустимий діапазон 1.0–40.0 т'],
            );
        }

        $steps = $tons / self::WEIGHT_STEP_TONS;

        if (abs($steps - round($steps)) > 0.000001) {
            throw ValidationException::config(
                'Максимальна маса авто має задаватись з кроком 0.5 т',
                ['maxVehicleWeightTons' => 'Крок значення — 0.5 т'],
            );
        }
    }

    private static function assertRange(int $value, int $min, int $max, string $field, string $label): void
    {
        if ($value < $min || $value > $max) {
            throw ValidationException::config(
                \sprintf('%s: допустимий діапазон %d–%d, отримано %d', $label, $min, $max, $value),
                [$field => \sprintf('Допустимий діапазон %d–%d', $min, $max)],
            );
        }
    }
}
