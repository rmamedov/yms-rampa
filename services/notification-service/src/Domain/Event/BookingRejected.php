<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Відмова в прийомі (NOT-17).
 *
 * Постачальнику негайно надсилається e-mail NOT-T8. Сповіщення водію —
 * фаза 2, поза MVP. Сповіщення критичне, opt-out не застосовується.
 */
final readonly class BookingRejected implements DomainEvent
{
    public function __construct(
        public string $bookingId,
        public \DateTimeImmutable $slotStartUtc,
        public string $storeExternalId,
        public string $vehicleNumber,
        /** Причина з довідника причин відмови. */
        public string $reason,
        public ?string $comment = null,
        public ?string $supplierId = null,
        public ?string $supplierEmail = null,
        public string $portalUrl = '',
        public ?\DateTimeImmutable $occurredAtUtc = null,
    ) {
    }

    public function eventName(): string
    {
        return 'BookingRejected';
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAtUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
