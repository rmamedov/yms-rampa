<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Branch\IneligibilityReason;

/**
 * Підсумок одного запуску синхронізації (SYNC-01, SYNC-03, INT-11).
 */
final readonly class SyncReport
{
    /**
     * Скільки поіменних записів про зміни зберігається в журналі.
     *
     * Повна вибірка MCP — сотні філій; за першої синхронізації «нових» буде
     * стільки ж. Зберігати їх усі в одному документі sync_log немає сенсу,
     * тому перелік обрізається, а справжня кількість лишається в лічильниках.
     */
    public const int CHANGE_LIST_LIMIT = 200;

    /**
     * @param array<string, array{externalId: string, changes: array<string, array{old: mixed, new: mixed}>}> $updatedDiff
     * @param list<string>                                                                                    $missingBranchIds
     * @param list<string>                                                                                    $archivedBranchIds
     * @param list<string>                                                                                    $errors
     * @param array<string, int>                                                                              $ineligibleByReason
     * @param list<BranchChange>                                                                              $changes
     */
    public function __construct(
        public SyncStatus $status,
        public SyncTrigger $trigger,
        public ?string $initiator,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
        public int $fetched = 0,
        public int $skipped = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $missing = 0,
        public int $archived = 0,
        public int $conflicts = 0,
        public int $ineligible = 0,
        public array $ineligibleByReason = [],
        public array $updatedDiff = [],
        public array $missingBranchIds = [],
        public array $archivedBranchIds = [],
        public array $errors = [],
        public array $changes = [],
    ) {
    }

    /**
     * Перелік змін для журналу — обрізаний до CHANGE_LIST_LIMIT.
     *
     * @return list<BranchChange>
     */
    public function storedChanges(): array
    {
        return \array_slice($this->changes, 0, self::CHANGE_LIST_LIMIT);
    }

    public function durationSeconds(): float
    {
        return (float) ($this->finishedAt->format('U.u') - $this->startedAt->format('U.u'));
    }

    public function isSuccessful(): bool
    {
        return SyncStatus::Failed !== $this->status;
    }

    /** Кількість придатних до активації записів у вибірці. */
    public function eligible(): int
    {
        return max(0, $this->fetched - $this->skipped - $this->ineligible);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'trigger' => $this->trigger->value,
            'initiator' => $this->initiator,
            'startedAt' => $this->startedAt->format(\DATE_ATOM),
            'finishedAt' => $this->finishedAt->format(\DATE_ATOM),
            'durationSeconds' => round($this->durationSeconds(), 3),
            'fetched' => $this->fetched,
            'skipped' => $this->skipped,
            'created' => $this->created,
            'updated' => $this->updated,
            'missing' => $this->missing,
            'archived' => $this->archived,
            'conflicts' => $this->conflicts,
            'ineligible' => $this->ineligible,
            'eligible' => $this->eligible(),
            'ineligibleByReason' => $this->ineligibleByReason,
            'errors' => $this->errors,
            'changes' => array_map(
                static fn (BranchChange $change): array => $change->toArray(),
                $this->storedChanges(),
            ),
            'changesTotal' => \count($this->changes),
        ];
    }

    /**
     * @param list<IneligibilityReason> $reasons
     * @param array<string, int>        $carry
     *
     * @return array<string, int>
     */
    public static function tallyReasons(array $reasons, array $carry = []): array
    {
        foreach ($reasons as $reason) {
            $carry[$reason->value] = ($carry[$reason->value] ?? 0) + 1;
        }

        return $carry;
    }
}
