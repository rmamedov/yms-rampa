<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Sync\SyncLogEntry;
use App\Domain\Sync\SyncLogRepository;
use App\Domain\Sync\SyncStatus;

/**
 * Журнал синхронізацій у памʼяті.
 */
final class InMemorySyncLogRepository implements SyncLogRepository
{
    /** @var array<string, SyncLogEntry> */
    private array $entries = [];

    public function save(SyncLogEntry $entry): void
    {
        $this->entries[$entry->id] = $entry;
    }

    public function find(string $id): ?SyncLogEntry
    {
        return $this->entries[$id] ?? null;
    }

    public function findRunning(): ?SyncLogEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->isRunning()) {
                return $entry;
            }
        }

        return null;
    }

    public function recent(int $limit, int $offset = 0): array
    {
        $sorted = $this->sorted();

        return \array_slice($sorted, $offset, $limit);
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function findLastSuccessful(): ?SyncLogEntry
    {
        foreach ($this->sorted() as $entry) {
            if (SyncStatus::Success === $entry->status || SyncStatus::Partial === $entry->status) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<SyncLogEntry> */
    private function sorted(): array
    {
        $sorted = array_values($this->entries);
        usort($sorted, static fn (SyncLogEntry $a, SyncLogEntry $b): int => $b->startedAt <=> $a->startedAt);

        return $sorted;
    }
}
