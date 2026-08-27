<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * По бронюванню зафіксовано затримку (прапорець delayed).
 *
 * Магазин-контур дізнається про це через realtime-канал (RT-02),
 * постачальник — SMS за шаблоном NOT-T6.
 */
final readonly class BookingDelaySet implements DomainEvent
{
    public function __construct(
        public string $bookingId,
        public \DateTimeImmutable $slotStartUtc,
        public string $storeExternalId,
        public string $reason,
        public ?string $supplierId = null,
        public ?string $supplierPhone = null,
        public ?string $supplierEmail = null,
        public ?\DateTimeImmutable $occurredAtUtc = null,
    ) {
    }

    public function eventName(): string
    {
        return 'BookingDelaySet';
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAtUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
