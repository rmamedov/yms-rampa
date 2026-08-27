<?php

declare(strict_types=1);

namespace App\Domain\Slot;

/**
 * Обчислюваний стан слота (SLOT-03).
 *
 * Пріоритет при накладанні, від найвищого:
 * past → blocked → booked → held → reserved → available.
 */
enum SlotState: string
{
    case Available = 'available';
    case Held = 'held';
    case Booked = 'booked';
    case Reserved = 'reserved';
    case Blocked = 'blocked';
    case Past = 'past';

    /** Чим більше число, тим вищий пріоритет при накладанні станів. */
    public function priority(): int
    {
        return match ($this) {
            self::Past => 60,
            self::Blocked => 50,
            self::Booked => 40,
            self::Held => 30,
            self::Reserved => 20,
            self::Available => 10,
        };
    }

    /** Чи може постачальник обрати слот у цьому стані. */
    public function isSelectable(): bool
    {
        return $this === self::Available;
    }
}
