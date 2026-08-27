<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\VehicleSnapshot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Поля запиту підтвердження бронювання (розділ 6.4).
 * `type` тут відсутній свідомо — його виставляє сервер.
 */
final readonly class NewBookingRequest
{
    public DateTimeImmutable $slotStart;

    public function __construct(
        public string $storeId,
        public string $rampId,
        DateTimeImmutable $slotStart,
        public VehicleSnapshot $vehicle,
        public int $palletsCount,
        public ?string $orderId = null,
        public ?string $driverId = null,
        /** Токен активної hold заявника (HOLD-03). */
        public ?string $holdToken = null,
        /** BOOK-04: обхід попередження VEHICLE_TIME_CONFLICT. */
        public bool $confirmConflict = false,
    ) {
        $this->slotStart = $slotStart->setTimezone(new DateTimeZone('UTC'));
    }
}
