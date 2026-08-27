<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Запис технічної колекції `outbox` (розділ 10.3.3).
 *
 * Три стани, і третій зʼявився не від хорошого життя:
 *   - у черзі      — publishedAt = null, failedAt = null;
 *   - опублікований — publishedAt заповнено: споживач ПРИЙНЯВ подію;
 *   - у карантині  — failedAt заповнено разом із причиною: споживач подію не
 *     взяв (сирота або нерозбірливий payload). Такий запис не блокує чергу,
 *     але й не зникає: його видно, і після виправлення payload його можна
 *     повернути в чергу (OutboxStore::requeueFailed).
 */
final readonly class OutboxRecord
{
    public function __construct(
        public string $id,
        public DomainEvent $event,
        public ?DateTimeImmutable $publishedAt = null,
        public int $attempts = 0,
        public ?DateTimeImmutable $failedAt = null,
        public ?string $failureReason = null,
    ) {
    }

    public function isPending(): bool
    {
        return null === $this->publishedAt && null === $this->failedAt;
    }

    /** Запис у карантині: споживач його не прийняв. */
    public function isQuarantined(): bool
    {
        return null !== $this->failedAt;
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
            'failedAt' => $this->failedAt?->format('Y-m-d\TH:i:s\Z'),
            'failureReason' => $this->failureReason,
        ];
    }
}
