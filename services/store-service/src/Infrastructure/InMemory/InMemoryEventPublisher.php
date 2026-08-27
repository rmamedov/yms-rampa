<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventPublisher;

/**
 * Збирач подій у памʼяті. У проді замінюється на Messenger-транспорт RabbitMQ
 * (exchange yms.domain / yms.integration, FUT-02) — контракт домену не змінюється.
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

    /** @return list<DomainEvent> */
    public function released(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /** @return list<DomainEvent> */
    public function all(): array
    {
        return $this->events;
    }

    /** @return list<DomainEvent> */
    public function ofName(string $name): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (DomainEvent $e): bool => $e->name() === $name,
        ));
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
