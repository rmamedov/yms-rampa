<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationRepository;

/**
 * Реалізація сховища сповіщень у памʼяті.
 *
 * Використовується юніт-тестами (працюють без MongoDB і Redis) та
 * dev-режимом, коли БД ще не піднято.
 */
final class InMemoryNotificationRepository implements NotificationRepository
{
    /** @var array<string, Notification> */
    private array $items = [];

    private int $sequence = 0;

    public function save(Notification $notification): void
    {
        $this->items[$notification->id()] = $notification;
    }

    public function find(string $id): ?Notification
    {
        return $this->items[$id] ?? null;
    }

    public function findDue(\DateTimeImmutable $now, int $limit = 100): array
    {
        $due = [];

        foreach ($this->items as $notification) {
            if (\count($due) >= $limit) {
                break;
            }
            if ($notification->isDue($now)) {
                $due[] = $notification;
            }
        }

        return $due;
    }

    public function findByCorrelationId(string $correlationId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (Notification $n): bool => $n->correlationId() === $correlationId,
        ));
    }

    public function nextIdentity(): string
    {
        return \sprintf('ntf_%08d', ++$this->sequence);
    }

    /** @return list<Notification> */
    public function all(): array
    {
        return array_values($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
        $this->sequence = 0;
    }
}
