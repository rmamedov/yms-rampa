<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/**
 * Журнал синхронізацій MCP (SYNC-01, INT-11).
 */
interface SyncLogRepository
{
    public function save(SyncLogEntry $entry): void;

    public function find(string $id): ?SyncLogEntry;

    /** Активний (незавершений) запуск — блокує повторний старт (SYNC-02, INT-05). */
    public function findRunning(): ?SyncLogEntry;

    /**
     * Серверна пагінація журналу (SYNC-01).
     *
     * @return list<SyncLogEntry> у порядку спадання startedAt
     */
    public function recent(int $limit, int $offset = 0): array;

    public function count(): int;

    public function findLastSuccessful(): ?SyncLogEntry;
}
