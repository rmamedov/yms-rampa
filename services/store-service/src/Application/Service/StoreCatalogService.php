<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Dto\BranchPresenter;
use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchCriteria;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Domain\Shared\Clock;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\ValidationException;

/**
 * Читання довідника магазинів для admin-web (5.2, 5.3.1).
 *
 * Ознака «Налаштовано» (STL-04) обчислюється саме тут, а не фронтендом:
 * джерело — чинна версія store_configs на поточний момент.
 */
final readonly class StoreCatalogService
{
    public function __construct(
        private BranchRepository $branches,
        private StoreConfigurationRepository $configurations,
        private Clock $clock,
    ) {
    }

    /**
     * STL-02, STL-03, STL-05: серверні фільтри, пошук, пагінація і сортування.
     *
     * @param array<string, mixed> $query
     * @param list<string>|null    $storeScope RBAC-17/RBAC-18: скоуп магазинів актора —
     *                                         null для мережевих ролей, перелік для магазинних;
     *                                         порожній перелік = порожня вибірка (RBAC-13),
     *                                         колекція фільтрується мовчки
     *
     * @return array<string, mixed>
     */
    public function list(array $query, ?array $storeScope = null): array
    {
        $now = $this->clock->now();
        $configuredIds = $this->configurations->configuredStoreIds($now);

        $criteria = $this->buildCriteria($query, $configuredIds, $storeScope);
        $page = $this->branches->search($criteria);

        $items = array_map(
            fn (Branch $b): array => BranchPresenter::row($b, $this->effectiveConfig($b->id(), $now)),
            $page->items,
        );

        return [
            'items' => $items,
            'total' => $page->total,
            'page' => $page->page,
            'perPage' => $page->perPage,
            'pages' => $page->pages(),
            // STL-06: порожній результат фільтрації — окреме повідомлення, а не порожня таблиця.
            'emptyMessage' => $page->isEmpty() ? 'Магазинів за заданими умовами не знайдено' : null,
        ];
    }

    /**
     * Службовий перелік магазинів, які реально беруть участь у роботі
     * (GET /internal/v1/stores, споживач — booking-service).
     *
     * Критерій «бере участь» — рівно той, за якого існує чинна конфігурація
     * прийому: ymsStatus=active І повна конфігурація за STL-04. Магазин на
     * паузі, архівний або ненастроєний сюди не потрапляє свідомо: для нього
     * GET /internal/v1/stores/{id}/settings відповідає 404, тож у перемикачі
     * філії він був би мертвим пунктом, який ламає кожен наступний екран.
     *
     * @param list<string>|null $storeIds RBAC-17: звуження переліком магазинів
     *                                    зі скоупу; null — без звуження,
     *                                    ПОРОЖНІЙ список = порожня вибірка (RBAC-13)
     *
     * @return array<string, mixed>
     */
    public function operationalStores(?array $storeIds = null, int $page = 1, int $perPage = 100): array
    {
        $now = $this->clock->now();
        $result = $this->branches->search(new BranchCriteria(
            statuses: [YmsStatus::Active],
            configured: true,
            configuredStoreIds: $this->configurations->configuredStoreIds($now),
            scopedStoreIds: $storeIds,
            page: max(1, $page),
            perPage: \in_array($perPage, BranchCriteria::ALLOWED_PER_PAGE, true)
                ? $perPage
                : BranchCriteria::ALLOWED_PER_PAGE[\count(BranchCriteria::ALLOWED_PER_PAGE) - 1],
        ));

        return [
            'items' => array_map(BranchPresenter::internalRow(...), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'perPage' => $result->perPage,
            'pages' => $result->pages(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(string $branchId): array
    {
        $branch = $this->requireBranch($branchId);

        return BranchPresenter::card($branch, $this->effectiveConfig($branchId, $this->clock->now()));
    }

    /**
     * Список міст довідника для фільтра admin-web (STL-02).
     *
     * @param list<string>|null $storeScope скоуп магазинів актора (див. list())
     *
     * @return list<array{city: string, storeCount: int}>
     */
    public function cities(?array $storeScope = null): array
    {
        return $this->branches->cities(new BranchCriteria(scopedStoreIds: $storeScope));
    }

    public function requireBranch(string $branchId): Branch
    {
        $branch = $this->branches->find($branchId);

        if (!$branch instanceof Branch) {
            throw NotFoundException::store($branchId);
        }

        return $branch;
    }

    public function effectiveConfig(string $branchId, ?\DateTimeImmutable $at = null): ?StoreConfiguration
    {
        return $this->configurations->findEffectiveAt($branchId, $at ?? $this->clock->now());
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $configuredIds
     * @param list<string>|null    $storeScope
     */
    private function buildCriteria(array $query, array $configuredIds, ?array $storeScope = null): BranchCriteria
    {
        $statuses = [];

        foreach (self::listParam($query, 'ymsStatus') as $value) {
            $status = YmsStatus::tryFrom($value);

            if (!$status instanceof YmsStatus) {
                throw ValidationException::field(
                    'ymsStatus',
                    \sprintf('Невідомий статус «%s»; допустимі: %s', $value, implode(', ', YmsStatus::values())),
                );
            }

            $statuses[] = $status;
        }

        $perPage = isset($query['perPage']) ? (int) $query['perPage'] : BranchCriteria::DEFAULT_PER_PAGE;

        if (!\in_array($perPage, BranchCriteria::ALLOWED_PER_PAGE, true)) {
            throw ValidationException::field(
                'perPage',
                \sprintf('Розмір сторінки має бути одним із: %s', implode(', ', BranchCriteria::ALLOWED_PER_PAGE)),
            );
        }

        $configured = null;

        if (isset($query['configured']) && '' !== $query['configured']) {
            $configured = \in_array($query['configured'], [true, 'true', '1', 1], true);
        }

        return new BranchCriteria(
            cities: self::listParam($query, 'city'),
            statuses: $statuses,
            query: isset($query['q']) ? (string) $query['q'] : null,
            configured: $configured,
            configuredStoreIds: $configuredIds,
            scopedStoreIds: $storeScope,
            page: max(1, isset($query['page']) ? (int) $query['page'] : 1),
            perPage: $perPage,
            sortBy: isset($query['sortBy']) ? (string) $query['sortBy'] : 'city',
            sortDirection: 'desc' === ($query['sortDirection'] ?? 'asc') ? 'desc' : 'asc',
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private static function listParam(array $query, string $key): array
    {
        $value = $query[$key] ?? null;

        if (null === $value || '' === $value) {
            return [];
        }

        if (\is_string($value)) {
            $value = explode(',', $value);
        }

        if (!\is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            $trimmed = trim((string) $item);

            if ('' !== $trimmed) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }
}
