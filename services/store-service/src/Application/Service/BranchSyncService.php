<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Shared\NotFoundException;
use App\Domain\Sync\BranchChange;
use App\Domain\Sync\BranchSource;
use App\Domain\Sync\BranchSynchronizer;
use App\Domain\Sync\SyncLogEntry;
use App\Domain\Sync\SyncLogRepository;
use App\Domain\Sync\SyncReport;
use App\Domain\Sync\SyncTrigger;

/**
 * Прикладний фасад синхронізації з MCP: запуск і журнал (5.6, 11.1).
 */
final readonly class BranchSyncService
{
    public function __construct(
        private BranchSynchronizer $synchronizer,
        private SyncLogRepository $syncLog,
        private BranchSource $defaultSource,
    ) {
    }

    /**
     * SYNC-02 / INT-05: ручний запуск позачергової синхронізації.
     */
    public function run(SyncTrigger $trigger, ?string $initiator = null, ?BranchSource $source = null): SyncReport
    {
        return $this->synchronizer->synchronize($source ?? $this->defaultSource, $trigger, $initiator);
    }

    /**
     * Журнал запусків із серверною пагінацією (SYNC-01).
     *
     * @return array<string, mixed>
     */
    public function log(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $entries = $this->syncLog->recent($perPage, ($page - 1) * $perPage);

        return [
            'items' => array_map(self::presentEntry(...), $entries),
            'total' => $this->syncLog->count(),
            'page' => $page,
            'perPage' => $perPage,
            // INT-13: банер «Останню синхронізацію не завершено, дані станом на …».
            'lastSuccessfulAt' => $this->syncLog->findLastSuccessful()?->finishedAt?->format(\DATE_ATOM),
            'running' => $this->syncLog->findRunning() instanceof SyncLogEntry,
        ];
    }

    /**
     * SYNC-01: деталізація одного запуску — поіменний перелік нових, змінених
     * і зниклих філій. У списку журналу його немає свідомо: перелік довгий,
     * а на екрані потрібні лічильники.
     *
     * @return array<string, mixed>
     */
    public function entry(string $id): array
    {
        $entry = $this->syncLog->find($id);

        if (!$entry instanceof SyncLogEntry) {
            throw NotFoundException::syncRun($id);
        }

        return self::presentEntry($entry) + [
            'changes' => array_map(
                static fn (BranchChange $change): array => $change->toArray(),
                $entry->changes,
            ),
            'changesTotal' => $entry->changesTotal,
            // Запуски, зроблені до появи деталізації, несуть лише лічильники —
            // вигадувати за них перелік не можна. Ознака рахується саме за
            // лічильниками: порожній перелік при нульових змінах — це чесне
            // «нічого не змінилось», а при ненульових — відсутній запис.
            'changesRecorded' => $entry->changesTotal > 0
                || 0 === $entry->created + $entry->updated + $entry->missing + $entry->archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentEntry(SyncLogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'status' => $entry->status->value,
            'statusLabel' => $entry->status->label(),
            'trigger' => $entry->trigger->value,
            'triggerLabel' => $entry->trigger->label(),
            'initiator' => $entry->initiator,
            'source' => $entry->source,
            'startedAt' => $entry->startedAt->format(\DATE_ATOM),
            'finishedAt' => $entry->finishedAt?->format(\DATE_ATOM),
            'durationSeconds' => $entry->durationSeconds(),
            'fetched' => $entry->fetched,
            'created' => $entry->created,
            'updated' => $entry->updated,
            'missing' => $entry->missing,
            'archived' => $entry->archived,
            'conflicts' => $entry->conflicts,
            'skipped' => $entry->skipped,
            'errors' => $entry->errors,
        ];
    }
}
