<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Слот звільнено.
 *
 * Подія входить до переліку джерел notification-service (NOT-02), але
 * власного шаблону в розділі 11.2.2 не має: у MVP вона лише знімає
 * заплановані нагадування по бронюванню, яке більше не займає слот.
 */
final readonly class SlotReleased implements DomainEvent
{
    public function __construct(
        public string $slotId,
        public string $storeId,
        public \DateTimeImmutable $slotStartUtc,
        public ?string $bookingId = null,
        public ?\DateTimeImmutable $occurredAtUtc = null,
    ) {
    }

    public function eventName(): string
    {
        return 'SlotReleased';
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAtUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
