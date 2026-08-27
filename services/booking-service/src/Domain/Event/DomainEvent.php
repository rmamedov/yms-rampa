<?php

declare(strict_types=1);

namespace App\Domain\Event;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Доменна подія в тому вигляді, в якому вона лягає в outbox і далі
 * публікується релеєм у RabbitMQ (DATA-16, at-least-once).
 */
final readonly class DomainEvent
{
    public DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public EventType $type,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        DateTimeImmutable $occurredAt,
    ) {
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function forBooking(
        EventType $type,
        string $bookingId,
        array $payload,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self($type, 'booking', $bookingId, $payload, $occurredAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eventType' => $this->type->value,
            'aggregateType' => $this->aggregateType,
            'aggregateId' => $this->aggregateId,
            'payload' => $this->payload,
            'occurredAt' => $this->occurredAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
