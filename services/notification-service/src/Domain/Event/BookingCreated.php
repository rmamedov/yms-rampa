<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Бронювання створено.
 *
 * Якщо заповнено `rescheduleOf` — це нове бронювання пари «перенесення»
 * (NOT-16): замість NOT-T2 надсилається єдине NOT-T7.
 */
final readonly class BookingCreated implements DomainEvent
{
    public function __construct(
        public string $bookingId,
        public BookingType $type,
        public \DateTimeImmutable $slotStartUtc,
        /** Номер філії з mcpData.externalId, напр. «1998». */
        public string $storeExternalId,
        public string $city,
        public string $address,
        public string $rampNumber,
        public string $vehicleNumber,
        public ?string $orderId = null,
        /** bookingId скасованого бронювання, якщо це перенесення слота. */
        public ?string $rescheduleOf = null,
        public ?string $supplierId = null,
        public ?string $supplierEmail = null,
        public ?string $supplierPhone = null,
        public ?string $driverId = null,
        public ?string $driverPhone = null,
        public string $portalUrl = '',
        public ?\DateTimeImmutable $occurredAtUtc = null,
    ) {
    }

    public function isReschedule(): bool
    {
        return null !== $this->rescheduleOf && '' !== $this->rescheduleOf;
    }

    public function eventName(): string
    {
        return 'BookingCreated';
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAtUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
