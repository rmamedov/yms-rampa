<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Reschedule\RescheduleRegistry;

/**
 * Реєстр перенесень у памʼяті (NOT-16).
 *
 * У проді роль сховища виконує Redis/Mongo з TTL: подія-пара може прийти
 * у будь-якому порядку, тому звʼязок треба памʼятати між обробками.
 */
final class InMemoryRescheduleRegistry implements RescheduleRegistry
{
    /** @var array<string, string> cancelledBookingId => newBookingId */
    private array $links = [];

    public function markRescheduled(string $cancelledBookingId, string $newBookingId): void
    {
        $this->links[$cancelledBookingId] = $newBookingId;
    }

    public function isRescheduled(string $cancelledBookingId): bool
    {
        return isset($this->links[$cancelledBookingId]);
    }

    public function newBookingFor(string $cancelledBookingId): ?string
    {
        return $this->links[$cancelledBookingId] ?? null;
    }

    public function clear(): void
    {
        $this->links = [];
    }
}
