<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchCriteria;
use App\Domain\Branch\IneligibilityReason;
use App\Domain\Branch\YmsStatus;
use App\Domain\Shared\FrozenClock;
use App\Domain\Sync\BranchSynchronizer;
use App\Domain\Sync\SyncReport;
use App\Domain\Sync\SyncStatus;
use App\Domain\Sync\SyncTrigger;
use App\Infrastructure\Fixture\FixtureBranchSource;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Infrastructure\InMemory\InMemoryEventPublisher;
use App\Infrastructure\InMemory\InMemorySyncLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Імпорт реальної фікстури довідника (455 записів) із застосуванням правил
 * фільтрації fixtures/README.md. Перевіряє, що сміттєві записи відсіяно
 * від активації, але збережено в довіднику (INT-07, STC-04).
 */
#[CoversClass(FixtureBranchSource::class)]
#[CoversClass(BranchSynchronizer::class)]
final class FixtureImportTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../../fixtures/silpo-branches.json';

    private InMemoryBranchRepository $branches;
    private SyncReport $report;

    protected function setUp(): void
    {
        if (!is_file(self::FIXTURE)) {
            self::markTestSkipped('Фікстура довідника філій недоступна: '.self::FIXTURE);
        }

        $this->branches = new InMemoryBranchRepository();

        $synchronizer = new BranchSynchronizer(
            $this->branches,
            new InMemorySyncLogRepository(),
            new InMemoryEventPublisher(),
            new FrozenClock('2026-08-27T03:00:00+00:00'),
        );

        $this->report = $synchronizer->synchronize(
            new FixtureBranchSource(self::FIXTURE),
            SyncTrigger::Import,
            'phpunit',
        );
    }

    public function testAllFourHundredFiftyFiveRecordsAreImported(): void
    {
        self::assertSame(SyncStatus::Success, $this->report->status);
        self::assertSame(455, $this->report->fetched);
        self::assertSame(455, $this->report->created);
        self::assertSame(0, $this->report->skipped, 'усі записи фікстури відповідають контракту MCP');
        self::assertSame(455, $this->branches->count());
    }

    /** Правила фільтрації відсівають рівно 10 записів із 455. */
    public function testTenRecordsAreMarkedIneligible(): void
    {
        self::assertSame(10, $this->report->ineligible);
        self::assertSame(445, $this->report->eligible());
    }

    /** Розподіл причин збігається з аналізом даних у fixtures/README.md. */
    public function testIneligibilityBreakdownMatchesFixtureAnalysis(): void
    {
        self::assertSame(
            [
                IneligibilityReason::DeletedExternalId->value => 5,
                IneligibilityReason::EmptyCity->value => 8,
                IneligibilityReason::EmptyAddress->value => 7,
                IneligibilityReason::MissingCoordinates->value => 1,
                IneligibilityReason::CoordinatesOutsideUkraine->value => 3,
            ],
            $this->report->ineligibleByReason,
        );
    }

    /** Усі імпортовані філії — not_configured і невидимі постачальникам (INT-07). */
    public function testEveryImportedBranchStartsNotConfiguredAndInvisible(): void
    {
        foreach ($this->branches->findAll() as $branch) {
            self::assertSame(YmsStatus::NotConfigured, $branch->ymsStatus());
            self::assertFalse($branch->visibleToSuppliers());
            self::assertSame(0, $branch->missingSyncCount());
        }
    }

    /** Записи delete_* усе одно є в довіднику — щоб їх було видно в адмінці. */
    public function testDeletedBranchesRemainInCatalogButAreIneligible(): void
    {
        $deleted = array_values(array_filter(
            $this->branches->findAll(),
            static fn (Branch $b): bool => str_starts_with($b->externalId(), 'delete_'),
        ));

        self::assertCount(5, $deleted);

        foreach ($deleted as $branch) {
            self::assertFalse($branch->isEligible());
            self::assertContains(IneligibilityReason::DeletedExternalId, $branch->ineligibilityReasons());
        }
    }

    /** Тестові філії поза межами України позначені відповідною причиною. */
    public function testTestBranchesOutsideUkraineAreFlagged(): void
    {
        $outside = array_values(array_filter(
            $this->branches->findAll(),
            static fn (Branch $b): bool => \in_array(
                IneligibilityReason::CoordinatesOutsideUkraine,
                $b->ineligibilityReasons(),
                true,
            ),
        ));

        self::assertCount(3, $outside);

        $externalIds = array_map(static fn (Branch $b): string => $b->externalId(), $outside);
        sort($externalIds);

        self::assertSame(['3656', '567898', '791091'], $externalIds);
    }

    /** Філія 2505 — єдиний запис без координат. */
    public function testBranchWithoutCoordinatesIsFlagged(): void
    {
        $branch = $this->branches->findByExternalId('2505');

        self::assertInstanceOf(Branch::class, $branch);
        self::assertNull($branch->mcpData()->location);
        self::assertContains(IneligibilityReason::MissingCoordinates, $branch->ineligibilityReasons());

        // Єдиний запис фікстури без координат.
        $withoutCoordinates = array_filter(
            $this->branches->findAll(),
            static fn (Branch $b): bool => \in_array(
                IneligibilityReason::MissingCoordinates,
                $b->ineligibilityReasons(),
                true,
            ),
        );

        self::assertCount(1, $withoutCoordinates);
    }

    /** hasPickup=null у 131 записі нормалізовано у false. */
    public function testNullPickupIsNormalisedForEveryBranch(): void
    {
        $withoutPickup = 0;

        foreach ($this->branches->findAll() as $branch) {
            self::assertIsBool($branch->mcpData()->hasPickup);

            if (!$branch->mcpData()->hasPickup) {
                ++$withoutPickup;
            }
        }

        // 13 записів false + 131 запис null → 144 після нормалізації.
        self::assertSame(144, $withoutPickup);
    }

    /** Порожнє місто ламає екран вибору міста — його немає у списку міст. */
    public function testCityListNeverContainsEmptyCity(): void
    {
        $cities = $this->branches->cities(new BranchCriteria());

        self::assertSame(82, \count($cities), '83 унікальні ключі мінус один порожній');

        foreach ($cities as $city) {
            self::assertNotSame('', trim($city['city']));
        }
    }

    /** Пошук за externalId «1998» знаходить рівно одну філію (критерій приймання 5.2). */
    public function testSearchByExternalIdFindsSingleBranch(): void
    {
        $page = $this->branches->search(new BranchCriteria(query: '1998', perPage: 20));

        self::assertSame(1, $page->total);
        self::assertSame('1998', $page->items[0]->externalId());
        self::assertSame('Київ', $page->items[0]->city());
    }

    /** Фільтр «місто = Київ» комбінується зі статусом за логікою AND (STL-02). */
    public function testCityAndStatusFiltersCombineWithAnd(): void
    {
        $kyivNotConfigured = $this->branches->search(new BranchCriteria(
            cities: ['Київ'],
            statuses: [YmsStatus::NotConfigured],
            perPage: 100,
        ));

        $kyivActive = $this->branches->search(new BranchCriteria(
            cities: ['Київ'],
            statuses: [YmsStatus::Active],
            perPage: 100,
        ));

        self::assertGreaterThan(0, $kyivNotConfigured->total);
        self::assertSame(0, $kyivActive->total, 'після імпорту активних філій немає');

        foreach ($kyivNotConfigured->items as $branch) {
            self::assertSame('Київ', $branch->city());
        }
    }

    /** UI-01: зміна сторінки зберігає фільтри і не дублює записів. */
    public function testPaginationIsStableAcrossPages(): void
    {
        $first = $this->branches->search(new BranchCriteria(cities: ['Київ'], perPage: 20, page: 1));
        $second = $this->branches->search(new BranchCriteria(cities: ['Київ'], perPage: 20, page: 2));

        self::assertSame($first->total, $second->total);
        self::assertCount(20, $first->items);

        $firstIds = array_map(static fn (Branch $b): string => $b->id(), $first->items);
        $secondIds = array_map(static fn (Branch $b): string => $b->id(), $second->items);

        self::assertSame([], array_intersect($firstIds, $secondIds));
    }

    /** Повторний імпорт тієї самої фікстури нічого не змінює (ідемпотентність). */
    public function testSecondImportOfSameFixtureChangesNothing(): void
    {
        $synchronizer = new BranchSynchronizer(
            $this->branches,
            new InMemorySyncLogRepository(),
            new InMemoryEventPublisher(),
            new FrozenClock('2026-08-28T03:00:00+00:00'),
        );

        $report = $synchronizer->synchronize(new FixtureBranchSource(self::FIXTURE), SyncTrigger::Cron);

        self::assertSame(0, $report->created);
        self::assertSame(0, $report->updated);
        self::assertSame(0, $report->missing);
        self::assertSame(455, $this->branches->count());
    }
}
