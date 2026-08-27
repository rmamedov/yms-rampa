<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

/**
 * Знімок постачальника для міжсервісної перевірки бронювання (BOOK-02).
 *
 * booking-service під час бронювання має відповісти на одне питання:
 * «чи може постачальник X бронювати в магазині Y». Відповідь складається
 * рівно з двох правил (SUP-02 + SUP-03):
 *
 *   1) постачальник має бути активним;
 *   2) магазин має бути дозволеним — або режимом «всі магазини», або whitelist.
 *
 * Порядок правил важливий: призупинений постачальник не має доступу навіть
 * до магазину зі свого whitelist, тому статус перевіряється першим і саме
 * він потрапляє в причину відмови.
 *
 * Це проєкція агрегату Supplier: назовні віддаємо лише ті поля, які потрібні
 * бронюванню, без контактів, ЄДРПОУ та історії змін.
 */
final readonly class SupplierAccessSnapshot
{
    /** Постачальник призупинений (або архівований) — бронювати не може взагалі. */
    public const REASON_SUSPENDED = 'SUPPLIER_SUSPENDED';

    /** Постачальник активний, але цієї філії немає в його whitelist (SUP-03). */
    public const REASON_STORE_NOT_ALLOWED = 'SUPPLIER_STORE_NOT_ALLOWED';

    /**
     * @param list<string> $allowedStoreIds порожній перелік разом із allStores=true
     *                                     означає доступ до всіх філій мережі
     */
    private function __construct(
        public string $supplierId,
        public string $name,
        public SupplierStatus $status,
        public bool $allStores,
        public array $allowedStoreIds,
    ) {
    }

    public static function fromSupplier(Supplier $supplier): self
    {
        $access = $supplier->storeAccess();

        return new self(
            supplierId: $supplier->id(),
            name: $supplier->name(),
            // Статус беремо з isActive(), а не з поля status: архівований
            // постачальник (DATA-03) для бронювання так само призупинений.
            status: $supplier->isActive() ? SupplierStatus::Active : SupplierStatus::Suspended,
            allStores: $access->allStores,
            allowedStoreIds: $access->allStores ? [] : $access->storeIds,
        );
    }

    public function isActive(): bool
    {
        return SupplierStatus::Active === $this->status;
    }

    public function allows(string $storeId): bool
    {
        return null === $this->denialReason($storeId);
    }

    /**
     * Машинна причина відмови або null, якщо доступ є.
     */
    public function denialReason(string $storeId): ?string
    {
        if (!$this->isActive()) {
            return self::REASON_SUSPENDED;
        }

        if (!$this->allStores && !\in_array($storeId, $this->allowedStoreIds, true)) {
            return self::REASON_STORE_NOT_ALLOWED;
        }

        return null;
    }
}
