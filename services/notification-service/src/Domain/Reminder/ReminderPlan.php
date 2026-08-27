<?php

declare(strict_types=1);

namespace App\Domain\Reminder;

/**
 * Вхідні дані для планування нагадувань по бронюванню (NOT-06).
 */
final readonly class ReminderPlan
{
    public function __construct(
        public string $bookingId,
        public \DateTimeImmutable $slotStartUtc,
        public string $storeExternalId,
        public string $address,
        public string $rampNumber,
        /** Телефон водія — отримувач SMS NOT-T3 і NOT-T4. */
        public ?string $driverPhone = null,
        public ?string $driverId = null,
        /** E-mail постачальника — отримувач NOT-T3. */
        public ?string $supplierEmail = null,
        public ?string $supplierId = null,
    ) {
    }
}
