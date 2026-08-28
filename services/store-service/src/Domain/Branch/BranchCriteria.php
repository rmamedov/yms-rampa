<?php

declare(strict_types=1);

namespace App\Domain\Branch;

/**
 * Критерії серверної вибірки магазинів (STL-02, STL-03, STL-05, UI-01).
 *
 * Фільтри комбінуються за логікою AND; сортування за замовчуванням —
 * місто, потім externalId.
 */
final readonly class BranchCriteria
{
    public const int DEFAULT_PER_PAGE = 20;

    /** @var list<int> дозволені розміри сторінки (UI-01) */
    public const array ALLOWED_PER_PAGE = [20, 50, 100];

    /**
     * Спеціальне значення фільтра «Місто» — філії, у яких місто в довіднику
     * MCP порожнє або відсутнє. Без нього такі філії не потрапляли в жодне
     * значення фільтра і були недосяжні (STL-02): довідник /stores/cities
     * порожнє місто свідомо не повертає, бо воно ламає екран вибору міста
     * в кабінеті постачальника.
     *
     * Порожній рядок для цього не годиться: він губиться і в query-параметрі
     * (`city=`), і в переліку через кому (`city=Київ,`).
     */
    public const string CITY_NONE = '__none__';

    /**
     * @param list<string>          $cities             мультивибір міст
     * @param list<YmsStatus>       $statuses           мультивибір статусів
     * @param string|null           $query              пошук за externalId (точний/префіксний) або адресою (підрядок)
     * @param bool|null             $configured         фільтр «Налаштовано / Не налаштовано»
     * @param list<string>|null     $configuredStoreIds перелік налаштованих магазинів (обчислює прикладний шар)
     * @param list<string>|null     $scopedStoreIds     RBAC-17: скоуп-предикат `_id ∈ storeIds`;
     *                                                  null = без фільтра (скоуп «вся мережа», RBAC-16),
     *                                                  ПОРОЖНІЙ список = нуль доступу (RBAC-13),
     *                                                  тобто гарантовано порожня вибірка
     * @param bool|null             $visibleToSuppliers null = без фільтра
     */
    public function __construct(
        public array $cities = [],
        public array $statuses = [],
        public ?string $query = null,
        public ?bool $configured = null,
        public ?array $configuredStoreIds = null,
        public ?array $scopedStoreIds = null,
        public ?bool $visibleToSuppliers = null,
        public ?bool $eligibleOnly = null,
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public string $sortBy = 'city',
        public string $sortDirection = 'asc',
    ) {
    }

    public function offset(): int
    {
        return max(0, $this->page - 1) * $this->perPage;
    }

    /** Чи відповідає філія всім фільтрам (спільна логіка для InMemory-реалізації). */
    public function matches(Branch $branch): bool
    {
        // RBAC-13: порожній перелік магазинів у скоупі не пропускає ЖОДНОЇ філії.
        if (null !== $this->scopedStoreIds && !\in_array($branch->id(), $this->scopedStoreIds, true)) {
            return false;
        }

        if ([] !== $this->cities && !self::cityMatches($branch->city(), $this->cities)) {
            return false;
        }

        if ([] !== $this->statuses && !\in_array($branch->ymsStatus(), $this->statuses, true)) {
            return false;
        }

        if (null !== $this->visibleToSuppliers && $branch->visibleToSuppliers() !== $this->visibleToSuppliers) {
            return false;
        }

        if (null !== $this->eligibleOnly && $branch->isEligible() !== $this->eligibleOnly) {
            return false;
        }

        if (null !== $this->configured && null !== $this->configuredStoreIds) {
            $isConfigured = \in_array($branch->id(), $this->configuredStoreIds, true);

            if ($isConfigured !== $this->configured) {
                return false;
            }
        }

        return $this->matchesQuery($branch);
    }

    /**
     * Філія без міста збігається ЛИШЕ зі спеціальним значенням CITY_NONE:
     * порожній рядок у переліку міст не має власного змісту.
     *
     * @param list<string> $cities
     */
    public static function cityMatches(string $city, array $cities): bool
    {
        if ('' === trim($city)) {
            return \in_array(self::CITY_NONE, $cities, true);
        }

        return \in_array($city, $cities, true);
    }

    /**
     * STL-03: одне поле пошуку — externalId (точний або префіксний збіг)
     * АБО адреса (підрядок, без урахування регістру).
     */
    private function matchesQuery(Branch $branch): bool
    {
        $query = trim((string) $this->query);

        if ('' === $query) {
            return true;
        }

        $needle = mb_strtolower($query);

        if (str_starts_with(mb_strtolower($branch->externalId()), $needle)) {
            return true;
        }

        if (str_contains(mb_strtolower($branch->mcpData()->address), $needle)) {
            return true;
        }

        return str_contains(mb_strtolower($branch->effectiveAddress()), $needle);
    }
}
