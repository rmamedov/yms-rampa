<?php

declare(strict_types=1);

namespace App\Tests\Domain\Sync;

use App\Domain\Branch\Branch;
use App\Domain\Branch\YmsStatus;
use App\Domain\Event\BranchSynced;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\FrozenClock;
use App\Domain\Sync\BranchSourceException;
use App\Domain\Sync\BranchSynchronizer;
use App\Domain\Sync\SyncLogEntry;
use App\Domain\Sync\SyncStatus;
use App\Domain\Sync\SyncTrigger;
use App\Domain\Sync\SyncReport;
use App\Infrastructure\Fixture\ArrayBranchSource;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Infrastructure\InMemory\InMemoryEventPublisher;
use App\Infrastructure\InMemory\InMemorySyncLogRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Синхронізація довідника з MCP: INT-01, INT-06..INT-09, INT-14, SYNC-02..SYNC-04.
 */
#[CoversClass(BranchSynchronizer::class)]
final class BranchSynchronizerTest extends TestCase
{
    private const string SECOND_ID = '1eda8887-bf7c-6f38-b0cb-9503162b5586';

    private InMemoryBranchRepository $branches;
    private InMemorySyncLogRepository $syncLog;
    private InMemoryEventPublisher $events;
    private FrozenClock $clock;
    private BranchSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->branches = new InMemoryBranchRepository();
        $this->syncLog = new InMemorySyncLogRepository();
        $this->events = new InMemoryEventPublisher();
        $this->clock = new FrozenClock('2026-08-27T03:00:00+00:00');
        $this->synchronizer = new BranchSynchronizer(
            $this->branches,
            $this->syncLog,
            $this->events,
            $this->clock,
        );
    }

    /** INT-07: нова філія створюється зі статусом not_configured і подією BranchSynced. */
    public function testNewBranchIsCreatedAsNotConfigured(): void
    {
        $report = $this->sync([BranchFactory::mcpRow()]);

        self::assertSame(SyncStatus::Success, $report->status);
        self::assertSame(1, $report->created);
        self::assertSame(0, $report->updated);

        $branch = $this->branches->find(BranchFactory::KYIV_ID);

        self::assertInstanceOf(Branch::class, $branch);
        self::assertSame(YmsStatus::NotConfigured, $branch->ymsStatus());
        self::assertFalse($branch->visibleToSuppliers());

        $published = $this->events->ofName('BranchSynced');

        self::assertCount(1, $published);
        self::assertInstanceOf(BranchSynced::class, $published[0]);
        self::assertSame('created', $published[0]->changeType);
    }

    /** Критерій приймання 11.1: повторний синк без змін — 0 оновлень. */
    public function testRepeatedSyncWithoutChangesProducesNoUpdates(): void
    {
        $rows = [BranchFactory::mcpRow()];
        $this->sync($rows);
        $this->events->clear();

        $report = $this->sync($rows);

        self::assertSame(0, $report->created);
        self::assertSame(0, $report->updated);
        self::assertSame(0, $report->missing);
        self::assertSame([], $this->events->ofName('BranchSynced'));
    }

    /** INT-08: змінена адреса оновлює лише mcpData і не чіпає YMS-налаштування. */
    public function testChangedAddressUpdatesOnlyMcpBlock(): void
    {
        $this->sync([BranchFactory::mcpRow()]);

        $branch = $this->branches->find(BranchFactory::KYIV_ID);
        self::assertInstanceOf(Branch::class, $branch);
        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration()->readiness(), $this->clock->now());
        $branch->setVisibleToSuppliers(true, $this->clock->now());
        $branch->rename('Сільпо Івасюка', $this->clock->now());
        $this->branches->save($branch);

        $report = $this->sync([BranchFactory::mcpRow(['address' => 'просп. Володимира Івасюка, 50'])]);

        self::assertSame(1, $report->updated);

        $updated = $this->branches->find(BranchFactory::KYIV_ID);
        self::assertInstanceOf(Branch::class, $updated);
        self::assertSame('просп. Володимира Івасюка, 50', $updated->mcpData()->address);
        self::assertSame(YmsStatus::Active, $updated->ymsStatus());
        self::assertTrue($updated->visibleToSuppliers());
        self::assertSame('Сільпо Івасюка', $updated->displayName());
    }

    /** INT-06: зміна externalId не створює дубля — зіставлення лише за branchId. */
    public function testChangedExternalIdDoesNotCreateDuplicate(): void
    {
        $this->sync([BranchFactory::mcpRow()]);

        $report = $this->sync([BranchFactory::mcpRow(['externalId' => '2999'])]);

        self::assertSame(0, $report->created);
        self::assertSame(1, $report->updated);
        self::assertSame(1, $this->branches->count());
        self::assertSame('2999', $this->branches->find(BranchFactory::KYIV_ID)?->externalId());
    }

    /** INT-09 / SYNC-03: archived лише після 3 послідовних відсутностей. */
    public function testBranchIsArchivedOnlyAfterThreeConsecutiveMisses(): void
    {
        $this->sync([BranchFactory::mcpRow(), $this->secondRow()]);

        // Синк 1 без київської філії.
        $report1 = $this->sync([$this->secondRow()]);
        self::assertSame(1, $report1->missing);
        self::assertSame(0, $report1->archived);
        self::assertSame(1, $this->branches->find(BranchFactory::KYIV_ID)?->missingSyncCount());
        self::assertSame(YmsStatus::NotConfigured, $this->branches->find(BranchFactory::KYIV_ID)?->ymsStatus());

        // Синк 2.
        $report2 = $this->sync([$this->secondRow()]);
        self::assertSame(0, $report2->archived);
        self::assertSame(2, $this->branches->find(BranchFactory::KYIV_ID)?->missingSyncCount());

        // Синк 3 — архівація.
        $report3 = $this->sync([$this->secondRow()]);
        self::assertSame(1, $report3->archived);
        self::assertSame([BranchFactory::KYIV_ID], $report3->archivedBranchIds);

        $branch = $this->branches->find(BranchFactory::KYIV_ID);
        self::assertSame(YmsStatus::Archived, $branch?->ymsStatus());
        self::assertNotNull($branch?->archivedAt(), 'DATA-07: archivedAt заповнюється, документ не видаляється');
        self::assertSame(2, $this->branches->count(), 'філія не видаляється фізично');
    }

    /** INT-09: поява філії у вибірці скидає лічильник відсутностей. */
    public function testReappearanceResetsMissingCounter(): void
    {
        $this->sync([BranchFactory::mcpRow(), $this->secondRow()]);
        $this->sync([$this->secondRow()]);
        $this->sync([$this->secondRow()]);

        self::assertSame(2, $this->branches->find(BranchFactory::KYIV_ID)?->missingSyncCount());

        $this->sync([BranchFactory::mcpRow(), $this->secondRow()]);

        self::assertSame(0, $this->branches->find(BranchFactory::KYIV_ID)?->missingSyncCount());
        self::assertSame(YmsStatus::NotConfigured, $this->branches->find(BranchFactory::KYIV_ID)?->ymsStatus());

        // Після скидання відлік починається спочатку — двох синків замало.
        $this->sync([$this->secondRow()]);
        $this->sync([$this->secondRow()]);

        self::assertSame(YmsStatus::NotConfigured, $this->branches->find(BranchFactory::KYIV_ID)?->ymsStatus());
    }

    public function testArchivedBranchIsNotArchivedTwice(): void
    {
        $this->sync([BranchFactory::mcpRow(), $this->secondRow()]);

        for ($i = 0; $i < 3; ++$i) {
            $this->sync([$this->secondRow()]);
        }

        $report = $this->sync([$this->secondRow()]);

        self::assertSame(1, $report->missing);
        self::assertSame(0, $report->archived);
    }

    /** INT-14: невалідний запис пропускається, решта вибірки обробляється. */
    public function testInvalidRecordIsSkippedWithoutBreakingSync(): void
    {
        $invalid = BranchFactory::mcpRow(['branchId' => 'зламаний-uuid']);

        $report = $this->sync([$invalid, BranchFactory::mcpRow()]);

        self::assertSame(SyncStatus::Partial, $report->status);
        self::assertSame(2, $report->fetched);
        self::assertSame(1, $report->skipped);
        self::assertSame(1, $report->created);
        self::assertCount(1, $report->errors);
    }

    /** INT-14: пропущений запис не враховується як «зниклий». */
    public function testSkippedRecordDoesNotCountAsMissing(): void
    {
        $this->sync([BranchFactory::mcpRow()]);

        $report = $this->sync([BranchFactory::mcpRow(), ['branchId' => null]]);

        self::assertSame(1, $report->skipped);
        self::assertSame(0, $report->missing);
        self::assertSame(0, $report->archived);
    }

    public function testDuplicateBranchIdInOnePageIsSkipped(): void
    {
        $report = $this->sync([BranchFactory::mcpRow(), BranchFactory::mcpRow(['externalId' => '9999'])]);

        self::assertSame(1, $report->created);
        self::assertSame(1, $report->skipped);
        self::assertSame('1998', $this->branches->find(BranchFactory::KYIV_ID)?->externalId());
    }

    /** INT-01 / SYNC-04: обрив пагінації не застосовує жодних змін до БД. */
    public function testSourceFailureLeavesDatabaseUntouched(): void
    {
        $this->sync([BranchFactory::mcpRow()]);
        $before = $this->branches->find(BranchFactory::KYIV_ID)?->syncedAt();

        $source = new ArrayBranchSource();
        $source->fail(BranchSourceException::partialPagination(300, 455));

        $report = $this->synchronizer->synchronize($source, SyncTrigger::Cron);

        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertSame(0, $report->created);
        self::assertSame(0, $report->missing);
        self::assertSame(0, $report->archived);
        self::assertSame(1, $this->branches->count());
        self::assertEquals($before, $this->branches->find(BranchFactory::KYIV_ID)?->syncedAt());
    }

    /** SYNC-02 / INT-05: повторний запуск під час активної синхронізації заборонений. */
    public function testConcurrentSyncIsRejected(): void
    {
        $this->syncLog->save(SyncLogEntry::started(
            'running-1',
            SyncTrigger::Cron,
            'cron',
            $this->clock->now(),
        ));

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Синхронізація вже виконується');

        $this->synchronizer->synchronize(new ArrayBranchSource([]), SyncTrigger::Manual, 'admin@silpo.ua');
    }

    /** SYNC-01: кожен запуск фіксується в журналі з лічильниками. */
    public function testSyncLogRecordsCounters(): void
    {
        $this->sync([BranchFactory::mcpRow()], SyncTrigger::Manual, 'admin@silpo.ua');

        $entries = $this->syncLog->recent(10);

        self::assertCount(1, $entries);
        self::assertSame(SyncStatus::Success, $entries[0]->status);
        self::assertSame(SyncTrigger::Manual, $entries[0]->trigger);
        self::assertSame('admin@silpo.ua', $entries[0]->initiator);
        self::assertSame(1, $entries[0]->fetched);
        self::assertSame(1, $entries[0]->created);
        self::assertFalse($entries[0]->isRunning());
        self::assertNotNull($entries[0]->finishedAt);
    }

    public function testFailedSyncIsAlsoLogged(): void
    {
        $source = new ArrayBranchSource();
        $source->fail(BranchSourceException::unavailable('таймаут 30 с'));

        $this->synchronizer->synchronize($source, SyncTrigger::Cron, 'cron');

        $entries = $this->syncLog->recent(10);

        self::assertSame(SyncStatus::Failed, $entries[0]->status);
        self::assertNotSame([], $entries[0]->errors);
        self::assertNull($this->syncLog->findLastSuccessful());
    }

    /** Непридатні записи все одно імпортуються, але з переліком причин. */
    public function testIneligibleRecordsAreImportedWithReasons(): void
    {
        $report = $this->sync([
            BranchFactory::mcpRow(),
            BranchFactory::mcpRow([
                'branchId' => self::SECOND_ID,
                'externalId' => 'delete_filia',
                'city' => '',
                'address' => '',
            ]),
        ]);

        self::assertSame(2, $report->created);
        self::assertSame(1, $report->ineligible);
        self::assertSame(1, $report->eligible());
        self::assertSame(1, $report->ineligibleByReason['deleted_external_id']);
        self::assertSame(1, $report->ineligibleByReason['empty_city']);

        $branch = $this->branches->find(self::SECOND_ID);

        self::assertFalse($branch?->isEligible());
        self::assertSame(YmsStatus::NotConfigured, $branch?->ymsStatus());
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function sync(array $rows, SyncTrigger $trigger = SyncTrigger::Cron, ?string $initiator = null): SyncReport
    {
        $this->clock->advance('+1 day');

        return $this->synchronizer->synchronize(new ArrayBranchSource($rows), $trigger, $initiator);
    }

    /**
     * @return array<string, mixed>
     */
    private function secondRow(): array
    {
        return BranchFactory::mcpRow([
            'branchId' => self::SECOND_ID,
            'externalId' => '2025',
            'address' => 'вул. Бережанська, 22',
        ]);
    }
}
