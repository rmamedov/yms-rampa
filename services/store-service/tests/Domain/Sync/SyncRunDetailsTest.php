<?php

declare(strict_types=1);

namespace App\Tests\Domain\Sync;

use App\Application\Service\BranchSyncService;
use App\Domain\Shared\FrozenClock;
use App\Domain\Shared\NotFoundException;
use App\Domain\Sync\BranchChangeKind;
use App\Domain\Sync\BranchSynchronizer;
use App\Domain\Sync\SyncTrigger;
use App\Infrastructure\Fixture\ArrayBranchSource;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Infrastructure\InMemory\InMemoryEventPublisher;
use App\Infrastructure\InMemory\InMemorySyncLogRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * SYNC-01: журнал синхронізації має пояснювати, ЯКІ філії змінились,
 * а не лише скільки їх. Синхронізатор цей перелік рахував і раніше —
 * до журналу він не доходив, тож із запису «змінено 2» користувач не міг
 * дізнатися, що саме сталося.
 */
#[CoversClass(BranchSynchronizer::class)]
#[CoversClass(BranchSyncService::class)]
final class SyncRunDetailsTest extends TestCase
{
    private const string SECOND_ID = '1eda8887-bf7c-6f38-b0cb-9503162b5586';

    private InMemoryBranchRepository $branches;
    private InMemorySyncLogRepository $syncLog;
    private FrozenClock $clock;
    private BranchSynchronizer $synchronizer;
    private BranchSyncService $service;

    protected function setUp(): void
    {
        $this->branches = new InMemoryBranchRepository();
        $this->syncLog = new InMemorySyncLogRepository();
        $this->clock = new FrozenClock('2026-08-27T03:00:00+00:00');
        $this->synchronizer = new BranchSynchronizer(
            $this->branches,
            $this->syncLog,
            new InMemoryEventPublisher(),
            $this->clock,
        );
        $this->service = new BranchSyncService(
            $this->synchronizer,
            $this->syncLog,
            new ArrayBranchSource([]),
        );
    }

    public function testNewBranchesAreListedByExternalId(): void
    {
        $report = $this->sync([BranchFactory::mcpRow(), $this->secondRow()]);

        self::assertCount(2, $report->changes);
        self::assertSame(
            [BranchChangeKind::Created, BranchChangeKind::Created],
            array_map(static fn ($c) => $c->kind, $report->changes),
        );
        self::assertSame(
            ['1998', '2222'],
            array_map(static fn ($c) => $c->externalId, $report->changes),
        );
    }

    public function testChangedFieldsAreRecordedWithOldAndNewValues(): void
    {
        $this->sync([BranchFactory::mcpRow()]);

        $report = $this->sync([BranchFactory::mcpRow(['address' => 'вул. Нова, 7'])]);

        self::assertCount(1, $report->changes);
        $change = $report->changes[0];

        self::assertSame(BranchChangeKind::Updated, $change->kind);
        self::assertSame('1998', $change->externalId);
        self::assertArrayHasKey('address', $change->fields);
        self::assertSame('просп. Володимира Івасюка, 46', $change->fields['address']['old']);
        self::assertSame('вул. Нова, 7', $change->fields['address']['new']);
    }

    public function testMissingBranchIsListedByName(): void
    {
        $this->sync([BranchFactory::mcpRow(), $this->secondRow()]);

        $report = $this->sync([$this->secondRow()]);

        self::assertCount(1, $report->changes);
        self::assertSame(BranchChangeKind::Missing, $report->changes[0]->kind);
        self::assertSame('1998', $report->changes[0]->externalId);
    }

    /** Деталізація доступна з журналу, а не лише одразу після запуску. */
    public function testDetailsSurviveInTheLog(): void
    {
        $this->sync([BranchFactory::mcpRow()]);
        $this->sync([BranchFactory::mcpRow(['address' => 'вул. Нова, 7'])]);

        $log = $this->service->log(1, 20);
        $latest = $log['items'][0];

        $details = $this->service->entry((string) $latest['id']);

        self::assertTrue($details['changesRecorded']);
        self::assertSame(1, $details['changesTotal']);
        self::assertSame('updated', $details['changes'][0]['kind']);
        self::assertSame('1998', $details['changes'][0]['externalId']);
        self::assertSame('Змінена', $details['changes'][0]['kindLabel']);
    }

    /**
     * Запуск, зроблений до появи деталізації: лічильники є, переліку немає.
     * Вигадувати за нього перелік не можна — це має бути видно у відповіді.
     */
    public function testLegacyRunWithoutRecordedChangesIsMarked(): void
    {
        $legacy = new \App\Domain\Sync\SyncLogEntry(
            id: 'legacy-1',
            status: \App\Domain\Sync\SyncStatus::Success,
            trigger: SyncTrigger::Cron,
            initiator: null,
            startedAt: new \DateTimeImmutable('2026-08-01T03:00:00+00:00'),
            finishedAt: new \DateTimeImmutable('2026-08-01T03:00:42+00:00'),
            fetched: 1200,
            updated: 12,
        );
        $this->syncLog->save($legacy);

        $details = $this->service->entry('legacy-1');

        self::assertFalse($details['changesRecorded']);
        self::assertSame([], $details['changes']);
        self::assertSame(12, $details['updated']);
    }

    public function testUnknownRunIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->entry('не-існує');
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function sync(array $rows): \App\Domain\Sync\SyncReport
    {
        $this->clock->advance('+1 day');

        return $this->synchronizer->synchronize(new ArrayBranchSource($rows), SyncTrigger::Cron, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function secondRow(): array
    {
        return BranchFactory::mcpRow([
            'branchId' => self::SECOND_ID,
            'externalId' => '2222',
            'city' => 'Львів',
            'address' => 'вул. Городоцька, 1',
        ]);
    }
}
