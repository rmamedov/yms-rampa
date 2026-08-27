<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventPublisher;

/**
 * Публікатор подій у пам'яті: збирає опубліковані події для перевірок
 * у тестах і для dev-режиму без RabbitMQ.
 */
final class InMemoryEventPublisher implements EventPublisher
{
    /** @var list<DomainEvent> */
    private array $events = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    /**
     * @return list<DomainEvent>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * @return list<DomainEvent>
     */
    public function ofType(string $eventType): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (DomainEvent $event): bool => $event->eventType() === $eventType,
        ));
    }

    public function last(): ?DomainEvent
    {
        return [] === $this->events ? null : $this->events[\count($this->events) - 1];
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
