<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Booking\Exception\InvalidPlateNumberException;
use App\Domain\Booking\Exception\VehicleTooHeavyException;
use InvalidArgumentException;

/**
 * Снапшот авто на момент бронювання (DATA-13): редагування автопарку
 * в partner-service не повинно змінювати минулі документи й друковані
 * маршрутні листи.
 */
final readonly class VehicleSnapshot
{
    public string $plateNumber;

    public function __construct(
        string $plateNumber,
        public float $weightTons,
        public ?string $brand = null,
    ) {
        $this->plateNumber = self::normalizePlate($plateNumber);

        if ($weightTons <= 0.0) {
            throw new InvalidArgumentException('Маса авто має бути більшою за 0 т');
        }
    }

    /** Держномер нормалізується до верхнього регістру без пробілів (розділ 6.4). */
    public static function normalizePlate(string $plateNumber): string
    {
        $normalized = mb_strtoupper(trim(preg_replace('/\s+/u', '', $plateNumber) ?? ''), 'UTF-8');
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length < 4 || $length > 12) {
            throw new InvalidPlateNumberException($plateNumber);
        }

        return $normalized;
    }

    /**
     * BOOK-01: єдиний код помилки тоннажу в системі — VEHICLE_TOO_HEAVY.
     *
     * @throws VehicleTooHeavyException якщо авто важче за ліміт філії
     */
    public function assertFitsStoreLimit(float $maxVehicleWeightTons): void
    {
        if ($this->weightTons > $maxVehicleWeightTons + 1e-9) {
            throw new VehicleTooHeavyException($maxVehicleWeightTons, $this->weightTons);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plateNumber' => $this->plateNumber,
            'weightTons' => $this->weightTons,
            'brand' => $this->brand,
        ];
    }
}
