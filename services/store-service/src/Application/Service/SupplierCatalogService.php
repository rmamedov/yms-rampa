<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Dto\BranchPresenter;
use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchCriteria;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Domain\Shared\Clock;
use App\Domain\Shared\NotFoundException;

/**
 * Каталог магазинів для supplier-web (контур partner).
 *
 * STC-04 / DATA-08: постачальник бачить магазин лише за умови
 * ymsStatus=active І visibleToSuppliers=true. Жоден інший запис
 * не повертається API постачальника за жодних фільтрів.
 */
final readonly class SupplierCatalogService
{
    public function __construct(
        private BranchRepository $branches,
        private StoreConfigurationRepository $configurations,
        private Clock $clock,
    ) {
    }

    /**
     * Список міст, у яких є видимі постачальнику магазини.
     *
     * @param list<string>|null $allowedStoreIds whitelist магазинів (SUP-03), див. stores()
     *
     * @return list<array{city: string, storeCount: int}>
     */
    public function cities(?array $allowedStoreIds = null): array
    {
        return $this->branches->cities($this->visibilityCriteria($allowedStoreIds));
    }

    /**
     * @param list<string>|null $allowedStoreIds whitelist магазинів постачальника (SUP-03):
     *                                           null — заголовок X-Store-Ids для партнерського
     *                                           контуру порожній «бо не застосовно», скоуп задає
     *                                           supplierId (RBAC-14) і видимість магазину
     *                                           (STC-04 / DATA-08); перелік — додаткове ЗВУЖЕННЯ
     *                                           вибірки, ніколи не розширення
     *
     * @return array<string, mixed>
     */
    public function stores(?string $city = null, ?array $allowedStoreIds = null, int $page = 1, int $perPage = 100): array
    {
        // RBAC-17: whitelist — предикат запиту до сховища, а не пост-фільтрація
        // сторінки в памʼяті (інакше total і пагінація брешуть).
        $criteria = new BranchCriteria(
            cities: null === $city || '' === trim($city) ? [] : [$city],
            statuses: [YmsStatus::Active],
            scopedStoreIds: $allowedStoreIds,
            visibleToSuppliers: true,
            eligibleOnly: true,
            page: max(1, $page),
            perPage: \in_array($perPage, BranchCriteria::ALLOWED_PER_PAGE, true) ? $perPage : 100,
        );

        $result = $this->branches->search($criteria);
        $now = $this->clock->now();

        $items = [];

        foreach ($result->items as $branch) {
            $items[] = BranchPresenter::supplierView(
                $branch,
                $this->configurations->findEffectiveAt($branch->id(), $now),
            );
        }

        return [
            'items' => $items,
            'total' => $result->total,
            'page' => $result->page,
            'perPage' => $result->perPage,
        ];
    }

    /**
     * Картка одного магазину для постачальника; невидимий магазин відповідає 404,
     * без розкриття причини недоступності (STC-04, DATA-08).
     *
     * @return array<string, mixed>
     */
    public function store(string $storeId, ?array $allowedStoreIds = null): array
    {
        $branch = $this->branches->find($storeId);

        if (!$this->isVisible($branch)) {
            throw NotFoundException::store($storeId);
        }

        \assert($branch instanceof Branch);

        if (null !== $allowedStoreIds && !\in_array($branch->id(), $allowedStoreIds, true)) {
            throw NotFoundException::store($storeId);
        }

        return BranchPresenter::supplierView(
            $branch,
            $this->configurations->findEffectiveAt($branch->id(), $this->clock->now()),
        );
    }

    public function isVisible(?Branch $branch): bool
    {
        if (!$branch instanceof Branch) {
            return false;
        }

        return YmsStatus::Active === $branch->ymsStatus()
            && $branch->visibleToSuppliers()
            && $branch->isEligible();
    }

    /**
     * @param list<string>|null $allowedStoreIds
     */
    private function visibilityCriteria(?array $allowedStoreIds = null): BranchCriteria
    {
        return new BranchCriteria(
            statuses: [YmsStatus::Active],
            scopedStoreIds: $allowedStoreIds,
            visibleToSuppliers: true,
            eligibleOnly: true,
        );
    }
}
