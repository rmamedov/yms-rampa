<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Запис технічної колекції `outbox` (розділ 10.3.3).
 */
final readonly class OutboxRecord
{
    public function __construct(
        public string $id,
        public DomainEvent $event,
        public ?DateTimeImmutable $publishedAt = null,
        public int $attempts = 0,
    ) {
    }

    public function isPending(): bool
    {
        return null === $this->publishedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            '_id' => $this->id,
            'aggregateType' => $this->event->aggregateType,
            'aggregateId' => $this->event->aggregateId,
            'eventType' => $this->event->type->value,
            'payload' => $this->event->payload,
            'occurredAt' => $this->event->occurredAt->format('Y-m-d\TH:i:s\Z'),
            'publishedAt' => $this->publishedAt?->format('Y-m-d\TH:i:s\Z'),
            'attempts' => $this->attempts,
        ];
    }
}
