<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/**
 * Запис журналу синхронізацій (колекція sync_log, 10.2.3, SYNC-01, INT-11).
 * Зберігання — мінімум 90 днів (TTL-індекс 180 днів).
 */
final readonly class SyncLogEntry
{
    /**
     * @param list<string>       $errors
     * @param list<BranchChange> $changes  поіменна деталізація (SYNC-01), обрізана
     *                                     до SyncReport::CHANGE_LIST_LIMIT
     */
    public function __construct(
        public string $id,
        public SyncStatus $status,
        public SyncTrigger $trigger,
        public ?string $initiator,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt = null,
        public int $fetched = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $missing = 0,
        public int $archived = 0,
        public int $conflicts = 0,
        public int $skipped = 0,
        public array $errors = [],
        public ?string $source = null,
        public array $changes = [],
        public int $changesTotal = 0,
    ) {
    }

    public static function started(
        string $id,
        SyncTrigger $trigger,
        ?string $initiator,
        \DateTimeImmutable $startedAt,
        ?string $source = null,
    ): self {
        return new self(
            id: $id,
            status: SyncStatus::Running,
            trigger: $trigger,
            initiator: $initiator,
            startedAt: $startedAt,
            source: $source,
        );
    }

    public function completedWith(SyncReport $report): self
    {
        return new self(
            id: $this->id,
            status: $report->status,
            trigger: $report->trigger,
            initiator: $report->initiator,
            startedAt: $this->startedAt,
            finishedAt: $report->finishedAt,
            fetched: $report->fetched,
            created: $report->created,
            updated: $report->updated,
            missing: $report->missing,
            archived: $report->archived,
            conflicts: $report->conflicts,
            skipped: $report->skipped,
            errors: $report->errors,
            source: $this->source,
            changes: $report->storedChanges(),
            changesTotal: \count($report->changes),
        );
    }

    public function isRunning(): bool
    {
        return SyncStatus::Running === $this->status;
    }

    public function durationSeconds(): ?float
    {
        if (!$this->finishedAt instanceof \DateTimeImmutable) {
            return null;
        }

        return (float) ($this->finishedAt->format('U.u') - $this->startedAt->format('U.u'));
    }
}
