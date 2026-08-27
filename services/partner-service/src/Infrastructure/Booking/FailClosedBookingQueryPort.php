<?php

declare(strict_types=1);

namespace App\Infrastructure\Booking;

use App\Domain\Booking\BookingQueryPort;

/**
 * Тимчасова реалізація порту booking-service до появи HTTP-клієнта.
 *
 * Стратегія fail-closed: поки достовірно невідомо, чи є бронювання,
 * вважаємо, що вони є. Наслідок — видалення постачальника (SUP-06) і
 * видалення авто (SUP-VEH-04) заблоковані, доступна лише деактивація.
 * Це безпечніше за протилежний варіант, коли можна знищити довідник,
 * на який посилаються чинні бронювання.
 */
final class FailClosedBookingQueryPort implements BookingQueryPort
{
    public function supplierHasAnyBookings(string $supplierId): bool
    {
        return true;
    }

    public function vehicleHasActiveBookings(string $vehicleId): bool
    {
        return true;
    }
}
