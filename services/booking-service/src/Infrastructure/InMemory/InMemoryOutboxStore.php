<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Event\DomainEvent;
use App\Domain\Outbox\OutboxRecord;
use App\Domain\Outbox\OutboxStore;
use DateTimeImmutable;

/**
 * Outbox у памʼяті — для юніт-тестів і dev-режиму без MongoDB.
 * Записи додаються в тому самому виклику, що й зміна бронювання (DATA-16).
 */
final class InMemoryOutboxStore implements OutboxStore
{
    /** @var array<string, OutboxRecord> */
    private array $records = [];

    private int $sequence = 0;

    public function append(array $events): void
    {
        foreach ($events as $event) {
            $id = \sprintf('ob-%06d', ++$this->sequence);
            $this->records[$id] = new OutboxRecord($id, $event);
        }
    }

    public function pending(int $limit = 100): array
    {
        $pending = array_values(array_filter(
            $this->records,
            static fn (OutboxRecord $record) => $record->isPending(),
        ));

        usort($pending, static fn (OutboxRecord $a, OutboxRecord $b) => [$a->event->occurredAt, $a->id] <=> [$b->event->occurredAt, $b->id]);

        return \array_slice($pending, 0, $limit);
    }

    public function markPublished(string $recordId, DateTimeImmutable $publishedAt): void
    {
        $record = $this->records[$recordId] ?? null;

        if (null === $record) {
            return;
        }

        $this->records[$recordId] = new OutboxRecord($record->id, $record->event, $publishedAt, $record->attempts + 1);
    }

    /**
     * @return list<OutboxRecord>
     */
    public function all(): array
    {
        return array_values($this->records);
    }

    /**
     * Усі події вказаного типу — зручно для перевірок у тестах.
     *
     * @return list<DomainEvent>
     */
    public function eventsOfType(string $eventType): array
    {
        $events = [];

        foreach ($this->records as $record) {
            if ($record->event->type->value === $eventType) {
                $events[] = $record->event;
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return array_map(
            static fn (OutboxRecord $record) => $record->event->type->value,
            array_values($this->records),
        );
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
