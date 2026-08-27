<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Reminder\ScheduledReminder;
use App\Domain\Reminder\ScheduledReminderRepository;

/**
 * Сховище запланованих нагадувань у памʼяті (NOT-06).
 */
final class InMemoryScheduledReminderRepository implements ScheduledReminderRepository
{
    /** @var array<string, ScheduledReminder> */
    private array $items = [];

    private int $sequence = 0;

    public function save(ScheduledReminder $reminder): void
    {
        $this->items[$reminder->id()] = $reminder;
    }

    public function findDue(\DateTimeImmutable $now, int $limit = 100): array
    {
        $due = [];

        foreach ($this->items as $reminder) {
            if (\count($due) >= $limit) {
                break;
            }
            if ($reminder->isDue($now)) {
                $due[] = $reminder;
            }
        }

        return $due;
    }

    public function findByBookingId(string $bookingId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ScheduledReminder $r): bool => $r->bookingId() === $bookingId,
        ));
    }

    public function nextIdentity(): string
    {
        return \sprintf('rmd_%08d', ++$this->sequence);
    }

    /** @return list<ScheduledReminder> */
    public function all(): array
    {
        return array_values($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
        $this->sequence = 0;
    }
}
