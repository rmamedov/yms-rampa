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
    public const array ALLOWED_SLOT_SIZES = [15, 20, 30, 60];
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

        if (!\in_array($slotSizeMinutes, self::ALLOWED_SLOT_SIZES, true)) {
            throw new InvalidArgumentException(\sprintf(
                'slotSizeMinutes має бути одним з %s, отримано %d',
                implode('/', self::ALLOWED_SLOT_SIZES),
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

    /** @return list<Ramp> */
    public function activeRamps(): array
    {
        return array_values(array_filter($this->ramps, static fn (Ramp $ramp) => $ramp->active));
    }
}
