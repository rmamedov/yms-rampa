<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Тип бронювання (поле type події BookingCreated, розділ 1.5 SRS).
 */
enum BookingType: string
{
    case Scheduled = 'scheduled';
    case WalkIn = 'walk_in';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Заплановане',
            self::WalkIn => 'Позапланове (walk-in)',
        };
    }
}
