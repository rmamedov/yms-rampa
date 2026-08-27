<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Service\SupplierCatalogService;
use App\Domain\Branch\Branch;
use App\Domain\Branch\YmsStatus;
use App\Domain\Shared\FrozenClock;
use App\Domain\Shared\NotFoundException;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Infrastructure\InMemory\InMemoryStoreConfigurationRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Видимість магазинів постачальнику: STC-04, DATA-08, SUP-03.
 */
#[CoversClass(SupplierCatalogService::class)]
final class SupplierCatalogServiceTest extends TestCase
{
    private const string LVIV_ID = '1eda8887-bf7c-6f38-b0cb-9503162b5586';
    private const string ODESA_ID = '1eda888d-b60d-66f4-a557-03a302d993f3';

    private InMemoryBranchRepository $branches;
    private InMemoryStoreConfigurationRepository $configs;
    private SupplierCatalogService $service;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->branches = new InMemoryBranchRepository();
        $this->configs = new InMemoryStoreConfigurationRepository();
        $this->clock = new FrozenClock('2026-08-27T08:00:00+00:00');
        $this->service = new SupplierCatalogService($this->branches, $this->configs, $this->clock);
    }

    /** STC-04: видно лише active + visibleToSuppliers. */
    public function testOnlyActiveAndVisibleStoresAreReturned(): void
    {
        $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');
        $this->activeButHidden(self::LVIV_ID, 'Львів', '2025');
        $this->notConfigured(self::ODESA_ID, 'Одеса', '3319');

        $result = $this->service->stores();

        self::assertSame(1, $result['total']);
        self::assertSame('1998', $result['items'][0]['externalId']);
    }

    public function testPausedStoreDisappearsFromSupplierCatalog(): void
    {
        $branch = $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');

        self::assertSame(1, $this->service->stores()['total']);

        $branch->changeStatus(YmsStatus::Paused, BranchFactory::completeConfiguration()->readiness(), $this->clock->now());
        $this->branches->save($branch);

        self::assertSame(0, $this->service->stores()['total']);
    }

    /** Список міст містить лише міста з видимими магазинами. */
    public function testCitiesListOnlyIncludesCitiesWithVisibleStores(): void
    {
        $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');
        $this->activeButHidden(self::LVIV_ID, 'Львів', '2025');

        $cities = $this->service->cities();

        self::assertSame([['city' => 'Київ', 'storeCount' => 1]], $cities);
    }

    public function testStoresAreFilteredByCity(): void
    {
        $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');
        $this->activeVisible(self::LVIV_ID, 'Львів', '2025');

        self::assertSame(1, $this->service->stores('Львів')['total']);
        self::assertSame('2025', $this->service->stores('Львів')['items'][0]['externalId']);
        self::assertSame(0, $this->service->stores('Одеса')['total']);
    }

    /** SUP-03: whitelist магазинів постачальника звужує вибірку. */
    public function testWhitelistLimitsVisibleStores(): void
    {
        $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');
        $this->activeVisible(self::LVIV_ID, 'Львів', '2025');

        $all = $this->service->stores(null, null);
        $limited = $this->service->stores(null, [self::LVIV_ID]);

        self::assertSame(2, $all['total']);
        self::assertSame(1, $limited['total']);
        self::assertSame('2025', $limited['items'][0]['externalId']);
    }

    /** Невидимий магазин — 404 без розкриття причини. */
    public function testHiddenStoreReturnsNotFound(): void
    {
        $this->activeButHidden(self::LVIV_ID, 'Львів', '2025');

        $this->expectException(NotFoundException::class);

        $this->service->store(self::LVIV_ID);
    }

    public function testStoreOutsideWhitelistReturnsNotFound(): void
    {
        $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');

        $this->expectException(NotFoundException::class);

        $this->service->store(BranchFactory::KYIV_ID, [self::LVIV_ID]);
    }

    /** STC-07: постачальнику показується addressOverride замість адреси MCP. */
    public function testSupplierSeesAddressOverrideButMcpCoordinates(): void
    {
        $branch = $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');
        $branch->setAddressOverride('вʼїзд з двору, шлагбаум', $this->clock->now());
        $this->branches->save($branch);

        $view = $this->service->store(BranchFactory::KYIV_ID);

        self::assertSame('вʼїзд з двору, шлагбаум', $view['address']);
        self::assertEqualsWithDelta(50.52022, $view['latitude'], 0.000001);
    }

    /** Постачальнику віддаються лише активні рампи (STC-22). */
    public function testSupplierViewExposesOnlyActiveRamps(): void
    {
        $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');

        $view = $this->service->store(BranchFactory::KYIV_ID);

        self::assertCount(2, $view['ramps']);
        self::assertSame(30, $view['slotSizeMinutes']);
        self::assertSame(10.0, $view['maxVehicleWeightTons']);
        self::assertSame(14, $view['bookingHorizonDays']);
    }

    /** Непридатний за даними MCP магазин ніколи не потрапляє в partner-контур. */
    public function testIneligibleBranchIsNeverVisible(): void
    {
        $branch = $this->activeVisible(BranchFactory::KYIV_ID, 'Київ', '1998');
        $branch->applyMcpUpdate(
            BranchFactory::mcpData(['city' => '', 'address' => '']),
            $this->clock->now(),
        );
        $this->branches->save($branch);

        self::assertFalse($this->service->isVisible($branch));
        self::assertSame(0, $this->service->stores()['total']);
    }

    private function activeVisible(string $branchId, string $city, string $externalId): Branch
    {
        $branch = $this->configured($branchId, $city, $externalId);
        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration($branchId)->readiness(), $this->clock->now());
        $branch->setVisibleToSuppliers(true, $this->clock->now());
        $this->branches->save($branch);

        return $branch;
    }

    private function activeButHidden(string $branchId, string $city, string $externalId): Branch
    {
        $branch = $this->configured($branchId, $city, $externalId);
        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration($branchId)->readiness(), $this->clock->now());
        $this->branches->save($branch);

        return $branch;
    }

    private function notConfigured(string $branchId, string $city, string $externalId): Branch
    {
        $branch = BranchFactory::branch(['branchId' => $branchId, 'city' => $city, 'externalId' => $externalId]);
        $this->branches->save($branch);

        return $branch;
    }

    private function configured(string $branchId, string $city, string $externalId): Branch
    {
        $this->configs->save(BranchFactory::completeConfiguration($branchId));

        return $this->notConfigured($branchId, $city, $externalId);
    }
}
