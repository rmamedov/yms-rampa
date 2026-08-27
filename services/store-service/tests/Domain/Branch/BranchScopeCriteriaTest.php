<?php

declare(strict_types=1);

namespace App\Tests\Domain\Branch;

use App\Domain\Branch\BranchCriteria;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * RBAC-17: скоуп магазинів — предикат вибірки (`_id ∈ storeIds`), а не
 * пост-фільтрація сторінки; RBAC-13: порожній перелік = порожня вибірка.
 */
#[CoversClass(BranchCriteria::class)]
final class BranchScopeCriteriaTest extends TestCase
{
    private const string LVIV_ID = '22222222-2222-4222-8222-222222222222';

    public function testNullScopeMeansNoFilter(): void
    {
        $page = $this->repository()->search(new BranchCriteria(scopedStoreIds: null, perPage: 20));

        self::assertSame(2, $page->total);
    }

    public function testListScopeKeepsOnlyItsStores(): void
    {
        $page = $this->repository()->search(new BranchCriteria(scopedStoreIds: [self::LVIV_ID], perPage: 20));

        self::assertSame(1, $page->total);
        self::assertSame(self::LVIV_ID, $page->items[0]->id());
    }

    /** Ключове: порожній перелік — нуль доступу, а не «усі магазини». */
    public function testEmptyScopeMatchesNothing(): void
    {
        $repository = $this->repository();

        $page = $repository->search(new BranchCriteria(scopedStoreIds: [], perPage: 20));

        self::assertSame(0, $page->total);
        self::assertSame([], $page->items);
        self::assertSame([], $repository->cities(new BranchCriteria(scopedStoreIds: [])));
    }

    public function testScopeNarrowsOtherFiltersInsteadOfReplacingThem(): void
    {
        $repository = $this->repository();

        $inScope = $repository->search(new BranchCriteria(
            cities: ['Львів'],
            scopedStoreIds: [self::LVIV_ID],
            perPage: 20,
        ));

        $outOfScope = $repository->search(new BranchCriteria(
            cities: ['Київ'],
            scopedStoreIds: [self::LVIV_ID],
            perPage: 20,
        ));

        self::assertSame(1, $inScope->total);
        self::assertSame(0, $outOfScope->total);
    }

    private function repository(): InMemoryBranchRepository
    {
        return new InMemoryBranchRepository([
            BranchFactory::branch(),
            BranchFactory::branch([
                'branchId' => self::LVIV_ID,
                'externalId' => '2025',
                'city' => 'Львів',
                'address' => 'вул. Городоцька, 1',
            ]),
        ]);
    }
}
