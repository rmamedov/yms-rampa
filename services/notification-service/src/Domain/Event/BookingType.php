<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Тип бронювання (поле `type` події BookingCreated).
 */
enum BookingType: string
{
    case Scheduled = 'scheduled';
    case WalkIn = 'walk_in';
}
