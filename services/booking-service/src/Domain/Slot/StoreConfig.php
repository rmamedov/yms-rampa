<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use InvalidArgumentException;

/**
 * Конфігурація магазину, з якої обчислюється сітка слотів (SLOT-01).
 * Джерело даних — store-service; тут це незмінний знімок на час обчислення.
 */
final readonly class StoreConfig
{
    /**
     * Розмір слоту задається з кроком 5 хвилин — той самий крок, що й в
     * інтервалів вікна прийому. Межі мають збігатися зі store-service
     * (App\Domain\Configuration\SlotSize): це той самий домен, просто по
     * різні боки службового виклику.
     */
    public const int SLOT_SIZE_MIN_MINUTES = 5;
    public const int SLOT_SIZE_MAX_MINUTES = 120;
    public const int SLOT_SIZE_STEP_MINUTES = 5;
    public const string TIMEZONE = 'Europe/Kyiv';

    /** @var list<ReceivingWindow> */
    public array $receivingWindows;

    /** @var list<Ramp> */
    public array $ramps;

    /** @var list<CalendarException> */
    public array $calendarExceptions;

    /**
     * @param list<ReceivingWindow>  $receivingWindows
     * @param list<Ramp>             $ramps
     * @param list<CalendarException> $calendarExceptions
     */
    public function __construct(
        public string $storeId,
        array $receivingWindows,
        public int $slotSizeMinutes,
        array $ramps,
        public float $maxVehicleWeightTons,
        public int $leadTimeMinutes = 60,
        public int $bookingHorizonDays = 14,
        array $calendarExceptions = [],
    ) {
        if ('' === $storeId) {
            throw new InvalidArgumentException('storeId не може бути порожнім');
        }

        if (
            $slotSizeMinutes < self::SLOT_SIZE_MIN_MINUTES
            || $slotSizeMinutes > self::SLOT_SIZE_MAX_MINUTES
            || 0 !== $slotSizeMinutes % self::SLOT_SIZE_STEP_MINUTES
        ) {
            throw new InvalidArgumentException(\sprintf(
                'slotSizeMinutes має бути кратним %d у межах %d..%d, отримано %d',
                self::SLOT_SIZE_STEP_MINUTES,
                self::SLOT_SIZE_MIN_MINUTES,
                self::SLOT_SIZE_MAX_MINUTES,
                $slotSizeMinutes,
            ));
        }

        if ($maxVehicleWeightTons < 1.0 || $maxVehicleWeightTons > 40.0) {
            throw new InvalidArgumentException(\sprintf(
                'maxVehicleWeightTons має бути в діапазоні 1.0–40.0, отримано %.1f',
                $maxVehicleWeightTons,
            ));
        }

        if (abs(fmod($maxVehicleWeightTons * 2, 1.0)) > 1e-9) {
            throw new InvalidArgumentException('maxVehicleWeightTons задається з кроком 0.5');
        }

        if ($leadTimeMinutes < 0 || $leadTimeMinutes > 1440) {
            throw new InvalidArgumentException(\sprintf(
                'leadTimeMinutes має бути в діапазоні 0–1440, отримано %d',
                $leadTimeMinutes,
            ));
        }

        if ($bookingHorizonDays < 1 || $bookingHorizonDays > 30) {
            throw new InvalidArgumentException(\sprintf(
                'bookingHorizonDays має бути в діапазоні 1–30, отримано %d',
                $bookingHorizonDays,
            ));
        }

        $seenDays = [];
        foreach ($receivingWindows as $window) {
            if (isset($seenDays[$window->dayOfWeek])) {
                throw new InvalidArgumentException(\sprintf(
                    'Для дня тижня %d задано більше одного вікна прийому',
                    $window->dayOfWeek,
                ));
            }
            $seenDays[$window->dayOfWeek] = true;
        }

        if ([] === $ramps) {
            throw new InvalidArgumentException('Магазин має містити щонайменше одну рампу');
        }

        $seenRamps = [];
        foreach ($ramps as $ramp) {
            if (isset($seenRamps[$ramp->rampId])) {
                throw new InvalidArgumentException(\sprintf('Дубль rampId "%s"', $ramp->rampId));
            }
            $seenRamps[$ramp->rampId] = true;
        }

        $this->receivingWindows = array_values($receivingWindows);
        $this->ramps = array_values($ramps);
        $this->calendarExceptions = array_values($calendarExceptions);
    }

    public function windowForDayOfWeek(int $dayOfWeek): ?ReceivingWindow
    {
        foreach ($this->receivingWindows as $window) {
            if ($window->dayOfWeek === $dayOfWeek) {
                return $window;
            }
        }

        return null;
    }

    public function calendarExceptionFor(string $date): ?CalendarException
    {
        foreach ($this->calendarExceptions as $exception) {
            if ($exception->date === $date) {
                return $exception;
            }
        }

        return null;
    }

    /**
     * Рампа за службовим ідентифікатором — разом з вимкненими: бронювання
     * на вимкненій рампі не зникає, і водієві однаково треба показати номер.
     */
    public function ramp(string $rampId): ?Ramp
    {
        foreach ($this->ramps as $ramp) {
            if ($ramp->rampId === $rampId) {
                return $ramp;
            }
        }

        return null;
    }

    /** @return list<Ramp> */
    public function activeRamps(): array
    {
        return array_values(array_filter($this->ramps, static fn (Ramp $ramp) => $ramp->active));
    }
}
