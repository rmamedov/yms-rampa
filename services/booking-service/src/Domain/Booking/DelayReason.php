<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Довідник причин затримки (DLY-01). Для `Other` обовʼязковий вільний текст.
 */
enum DelayReason: string
{
    case TrafficJam = 'затори';
    case Breakdown = 'поломка';
    case PreviousStop = 'затримка на попередній точці';
    case Other = 'інше';

    public function requiresComment(): bool
    {
        return self::Other === $this;
    }
}
