<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Бронювання скасовано.
 *
 * Якщо скасування є частиною перенесення слота (заповнено
 * `rescheduledToBookingId`), окреме NOT-T5 не надсилається — див. NOT-16.
 */
final readonly class BookingCancelled implements DomainEvent
{
    public function __construct(
        public string $bookingId,
        public \DateTimeImmutable $slotStartUtc,
        public string $storeExternalId,
        public string $reason,
        /** bookingId нового бронювання, якщо скасування — це перенесення. */
        public ?string $rescheduledToBookingId = null,
        public ?string $supplierId = null,
        public ?string $supplierEmail = null,
        public ?string $driverId = null,
        public ?string $driverPhone = null,
        public string $portalUrl = '',
        public ?\DateTimeImmutable $occurredAtUtc = null,
    ) {
    }

    public function isReschedule(): bool
    {
        return null !== $this->rescheduledToBookingId && '' !== $this->rescheduledToBookingId;
    }

    public function eventName(): string
    {
        return 'BookingCancelled';
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAtUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
