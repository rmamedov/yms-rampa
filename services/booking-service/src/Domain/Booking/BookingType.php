<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Тип бронювання. Виставляється сервером, а не клієнтом:
 * `scheduled` — постачальник через POST /bookings,
 * `walk_in` — позапланове прибуття, зареєстроване магазином (розділ 6.4.1).
 */
enum BookingType: string
{
    case Scheduled = 'scheduled';
    case WalkIn = 'walk_in';
}
