<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Booking\BookingQueryPort;
use App\Domain\Vehicle\PlateNumberNormalizer;

/**
 * Заглушка booking-service у пам'яті: дозволяє в тестах і dev-режимі
 * відтворити заборони SUP-06 і SUP-VEH-04 без піднятого booking-service.
 *
 * Авто ключується парою «постачальник + нормалізований держномер» — рівно
 * так, як питає справжній порт: id довідника booking-service не знає (DATA-13).
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

    public function vehicleHasActiveBookings(string $supplierId, string $plateNumber): bool
    {
        return isset($this->vehiclesWithActiveBookings[self::key($supplierId, $plateNumber)]);
    }

    public function registerSupplierBooking(string $supplierId): void
    {
        $this->suppliersWithBookings[$supplierId] = true;
    }

    public function registerVehicleActiveBooking(string $supplierId, string $plateNumber): void
    {
        $this->vehiclesWithActiveBookings[self::key($supplierId, $plateNumber)] = true;
    }

    public function reset(): void
    {
        $this->suppliersWithBookings = [];
        $this->vehiclesWithActiveBookings = [];
    }

    private static function key(string $supplierId, string $plateNumber): string
    {
        return $supplierId.'|'.PlateNumberNormalizer::normalize($plateNumber);
    }
}
