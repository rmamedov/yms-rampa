<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\McpData;
use App\Domain\Event\BranchSynced;
use App\Domain\Event\EventPublisher;
use App\Domain\Shared\Clock;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Uuid;

/**
 * Синхронізація довідника філій з MCP (INT-01, INT-06..INT-11, SYNC-02..SYNC-04).
 *
 * Алгоритм:
 *  1. Джерело віддає ПОВНУ вибірку або кидає BranchSourceException — тоді синк failed
 *     і жодна зміна до БД не застосовується (INT-01, SYNC-04).
 *  2. Зіставлення виключно за branchId (INT-06): зміна externalId/адреси/міста
 *     не створює дубля.
 *  3. Нова філія → ymsStatus=not_configured, подія BranchSynced (INT-07).
 *  4. Змінена філія → оновлюється лише блок mcpData; YMS-налаштування недоторкані (INT-08).
 *  5. Зникла філія → missingSyncCount+1; archived лише після 3 послідовних відсутностей;
 *     поява у вибірці скидає лічильник (INT-09, SYNC-03).
 *  6. Запис з порушенням контракту (немає branchId, невалідний UUID) пропускається,
 *     помилка фіксується в журналі, і він НЕ враховується при обчисленні «зниклих» (INT-14).
 */
final readonly class BranchSynchronizer
{
    public function __construct(
        private BranchRepository $branches,
        private SyncLogRepository $syncLog,
        private EventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @throws ConflictException якщо синхронізація вже виконується (SYNC-02, INT-05)
     */
    public function synchronize(
        BranchSource $source,
        SyncTrigger $trigger = SyncTrigger::Cron,
        ?string $initiator = null,
    ): SyncReport {
        if ($this->syncLog->findRunning() instanceof SyncLogEntry) {
            throw ConflictException::syncAlreadyRunning();
        }

        $startedAt = $this->clock->now();
        $entry = SyncLogEntry::started(Uuid::v4(), $trigger, $initiator, $startedAt, $source->describe());
        $this->syncLog->save($entry);

        try {
            $report = $this->run($source, $trigger, $initiator, $startedAt);
        } catch (BranchSourceException $e) {
            // INT-01/SYNC-04: атомарність — часткова вибірка не застосовується до БД.
            $report = new SyncReport(
                status: SyncStatus::Failed,
                trigger: $trigger,
                initiator: $initiator,
                startedAt: $startedAt,
                finishedAt: $this->clock->now(),
                errors: [$e->getMessage()],
            );
        }

        $this->syncLog->save($entry->completedWith($report));

        return $report;
    }

    private function run(
        BranchSource $source,
        SyncTrigger $trigger,
        ?string $initiator,
        \DateTimeImmutable $startedAt,
    ): SyncReport {
        /** @var array<string, McpData> $fresh */
        $fresh = [];
        $errors = [];
        $fetched = 0;
        $skipped = 0;

        // Крок 1. Повне вичитування у памʼять — БД поки не чіпаємо (атомарність INT-01).
        foreach ($source->fetchAll() as $row) {
            ++$fetched;

            try {
                $data = McpData::fromMcpRow($row);
            } catch (DomainException $e) {
                // INT-14: запис пропускається, решта вибірки обробляється.
                ++$skipped;
                $errors[] = \sprintf('Запис #%d відхилено: %s', $fetched, $e->getMessage());

                continue;
            }

            if (isset($fresh[$data->branchId])) {
                ++$skipped;
                $errors[] = \sprintf('Дубль branchId %s у вибірці MCP — використано перший запис', $data->branchId);

                continue;
            }

            $fresh[$data->branchId] = $data;
        }

        $syncedAt = $this->clock->now();

        $created = 0;
        $updated = 0;
        $missing = 0;
        $archived = 0;
        $ineligible = 0;
        $ineligibleByReason = [];
        $updatedDiff = [];
        $missingIds = [];
        $archivedIds = [];
        $changes = [];
        $events = [];
        $touched = [];

        // Крок 2. Зіставлення наявних філій за branchId (INT-06).
        foreach ($this->branches->findAll() as $branch) {
            $data = $fresh[$branch->id()] ?? null;

            if (null === $data) {
                // INT-09: філія відсутня у ПОВНІЙ вибірці MCP.
                ++$missing;
                $missingIds[] = $branch->id();
                $changes[] = new BranchChange(
                    BranchChangeKind::Missing,
                    $branch->id(),
                    $branch->externalId(),
                );

                if ($branch->markMissingInSync($syncedAt)) {
                    $branch->archiveBySync($syncedAt);
                    ++$archived;
                    $archivedIds[] = $branch->id();
                    $changes[] = new BranchChange(
                        BranchChangeKind::Archived,
                        $branch->id(),
                        $branch->externalId(),
                    );
                    $events[] = new BranchSynced(
                        $branch->id(),
                        $branch->externalId(),
                        'archived',
                        [],
                        $syncedAt,
                    );
                }

                $touched[] = $branch;

                continue;
            }

            unset($fresh[$branch->id()]);

            $fieldChanges = $branch->applyMcpUpdate($data, $syncedAt);

            if ([] !== $fieldChanges) {
                ++$updated;
                $updatedDiff[$branch->id()] = ['externalId' => $branch->externalId(), 'changes' => $fieldChanges];
                $changes[] = new BranchChange(
                    BranchChangeKind::Updated,
                    $branch->id(),
                    $branch->externalId(),
                    $fieldChanges,
                );
                $events[] = new BranchSynced($branch->id(), $branch->externalId(), 'updated', $fieldChanges, $syncedAt);
            }

            if (!$branch->isEligible()) {
                ++$ineligible;
                $ineligibleByReason = SyncReport::tallyReasons($branch->ineligibilityReasons(), $ineligibleByReason);
            }

            $touched[] = $branch;
        }

        // Крок 3. Те, що лишилось у $fresh, — нові філії (INT-07).
        foreach ($fresh as $data) {
            $branch = Branch::createFromMcp($data, $syncedAt);
            ++$created;

            if (!$branch->isEligible()) {
                ++$ineligible;
                $ineligibleByReason = SyncReport::tallyReasons($branch->ineligibilityReasons(), $ineligibleByReason);
            }

            $changes[] = new BranchChange(
                BranchChangeKind::Created,
                $branch->id(),
                $branch->externalId(),
            );
            $events[] = new BranchSynced($branch->id(), $branch->externalId(), 'created', [], $syncedAt);
            $touched[] = $branch;
        }

        $this->branches->saveAll($touched);
        $this->events->publish(...$events);

        return new SyncReport(
            status: [] === $errors ? SyncStatus::Success : SyncStatus::Partial,
            trigger: $trigger,
            initiator: $initiator,
            startedAt: $startedAt,
            finishedAt: $this->clock->now(),
            fetched: $fetched,
            skipped: $skipped,
            created: $created,
            updated: $updated,
            missing: $missing,
            archived: $archived,
            conflicts: 0,
            ineligible: $ineligible,
            ineligibleByReason: $ineligibleByReason,
            updatedDiff: $updatedDiff,
            missingBranchIds: $missingIds,
            archivedBranchIds: $archivedIds,
            errors: $errors,
            changes: $changes,
        );
    }
}
