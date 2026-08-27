<?php

declare(strict_types=1);

namespace App\Domain\Slot;

/**
 * Канонічні стани слота (глосарій 1.5 SRS).
 */
enum SlotState: string
{
    case Available = 'available';
    case Held = 'held';
    case Booked = 'booked';
    case Reserved = 'reserved';
    case Blocked = 'blocked';
    case Past = 'past';

    /**
     * KPI-01: blocked і past слоти виключаються зі знаменника
     * (available_minutes) формули утилізації рамп.
     */
    public function countsInAvailableMinutes(): bool
    {
        return match ($this) {
            self::Blocked, self::Past => false,
            default => true,
        };
    }

    /**
     * KPI-01: чисельник — слото-хвилини фактично заброньованих слотів.
     * held (тимчасовий холд у Redis) і reserved (резерв розкладу без бронювання)
     * бронюваннями не є і в чисельник не входять.
     */
    public function countsInBookedMinutes(): bool
    {
        return $this === self::Booked;
    }
}
