<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Booking\BookingQueryPort;

/**
 * Заглушка booking-service у пам'яті: дозволяє в тестах і dev-режимі
 * відтворити заборони SUP-06 і SUP-VEH-04 без піднятого booking-service.
 */
final class InMemoryBookingQueryPort implements BookingQueryPort
{
    /** @var array<string, true> */
    private array $suppliersWithBookings = [];

    /** @var array<string, true> */
    private array $vehiclesWithActiveBookings = [];

    public function supplierHasAnyBookings(string $supplierId): bool
    {
        return isset($this->suppliersWithBookings[$supplierId]);
    }

    public function vehicleHasActiveBookings(string $vehicleId): bool
    {
        return isset($this->vehiclesWithActiveBookings[$vehicleId]);
    }

    public function registerSupplierBooking(string $supplierId): void
    {
        $this->suppliersWithBookings[$supplierId] = true;
    }

    public function registerVehicleActiveBooking(string $vehicleId): void
    {
        $this->vehiclesWithActiveBookings[$vehicleId] = true;
    }

    public function reset(): void
    {
        $this->suppliersWithBookings = [];
        $this->vehiclesWithActiveBookings = [];
    }
}
