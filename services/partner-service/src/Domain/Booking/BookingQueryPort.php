<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Порт читання booking-service (звернення через api-gateway; прямі читання
 * чужої БД заборонені — розділ 10).
 *
 * Потрібен для двох заборон:
 *  - SUP-06: постачальника з історією бронювань не можна видалити;
 *  - SUP-VEH-04: авто, прив'язане до активних бронювань, не можна видалити.
 */
interface BookingQueryPort
{
    /** SUP-06: чи існує хоч одне бронювання постачальника будь-якого статусу. */
    public function supplierHasAnyBookings(string $supplierId): bool;

    /** SUP-VEH-04: чи є в авто активні бронювання (booked/arrived/unloading). */
    public function vehicleHasActiveBookings(string $vehicleId): bool;
}
