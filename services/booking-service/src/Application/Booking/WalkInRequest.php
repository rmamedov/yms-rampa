<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\VehicleSnapshot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Поля реєстрації позапланового прибуття (WALK-02).
 *
 * Постачальник — або зі списку partner-service (`supplierId`), або
 * «поза системою» з вільним текстом назви (`supplierName`).
 */
final readonly class WalkInRequest
{
    public DateTimeImmutable $slotStart;

    public function __construct(
        public string $storeId,
        public string $rampId,
        DateTimeImmutable $slotStart,
        public VehicleSnapshot $vehicle,
        public int $palletsCount,
        public ?string $supplierId = null,
        public ?string $supplierName = null,
        public ?string $orderId = null,
    ) {
        $this->slotStart = $slotStart->setTimezone(new DateTimeZone('UTC'));
    }
}
