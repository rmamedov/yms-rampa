<?php

declare(strict_types=1);

namespace App\Tests\Domain\Branch;

use App\Application\Service\StoreCatalogService;
use App\Domain\Branch\BranchCriteria;
use App\Domain\Shared\FrozenClock;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Infrastructure\InMemory\InMemoryStoreConfigurationRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * STL-02: філії, у яких MCP не віддає міста, мають бути досяжні фільтром.
 *
 * Довідник /stores/cities порожнє місто свідомо не повертає (воно ламає екран
 * вибору міста в кабінеті постачальника), тому такі філії не потрапляли в
 * жодне значення фільтра і знайти їх фільтром було неможливо.
 */
#[CoversClass(BranchCriteria::class)]
#[CoversClass(StoreCatalogService::class)]
final class BranchCityFilterTest extends TestCase
{
    private const string HOMELESS_ID = '33333333-3333-4333-8333-333333333333';

    public function testCityFilterIgnoresBranchesWithoutCity(): void
    {
        $page = $this->repository()->search(new BranchCriteria(cities: ['Київ'], perPage: 20));

        self::assertSame(1, $page->total);
        self::assertSame(BranchFactory::KYIV_ID, $page->items[0]->id());
    }

    public function testSpecialValueSelectsExactlyBranchesWithoutCity(): void
    {
        $page = $this->repository()->search(
            new BranchCriteria(cities: [BranchCriteria::CITY_NONE], perPage: 20),
        );

        self::assertSame(1, $page->total);
        self::assertSame(self::HOMELESS_ID, $page->items[0]->id());
    }

    public function testSpecialValueCombinesWithNamedCities(): void
    {
        $page = $this->repository()->search(new BranchCriteria(
            cities: ['Київ', BranchCriteria::CITY_NONE],
            perPage: 20,
        ));

        self::assertSame(2, $page->total);
    }

    /** Кожна філія довідника має потрапляти або в місто, або у «без міста». */
    public function testCityFilterCoversWholeNetwork(): void
    {
        $repository = $this->repository();
        $catalog = new StoreCatalogService(
            $repository,
            new InMemoryStoreConfigurationRepository(),
            new FrozenClock(new \DateTimeImmutable('2026-08-27T09:00:00+00:00')),
        );

        $filter = $catalog->cityFilter();
        $covered = array_sum(array_column($filter['items'], 'storeCount'));

        self::assertSame(1, $filter['withoutCity']);
        self::assertSame(3, $covered + $filter['withoutCity']);
        self::assertSame(
            ['Київ', 'Львів'],
            array_column($filter['items'], 'city'),
            'порожнє місто у переліку міст не зʼявляється',
        );
    }

    private function repository(): InMemoryBranchRepository
    {
        return new InMemoryBranchRepository([
            BranchFactory::branch(),
            BranchFactory::branch([
                'branchId' => '22222222-2222-4222-8222-222222222222',
                'externalId' => '2025',
                'city' => 'Львів',
                'address' => 'вул. Городоцька, 1',
            ]),
            BranchFactory::branch([
                'branchId' => self::HOMELESS_ID,
                'externalId' => '9001',
                'city' => '',
                'address' => 'вул. Без Міста, 1',
            ]),
        ]);
    }
}
