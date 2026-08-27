<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

/**
 * Знімок постачальника з partner-service, потрібний для перевірки BOOK-02.
 */
final readonly class SupplierInfo
{
    /** @var list<string> */
    public array $allowedStoreIds;

    /**
     * @param list<string> $allowedStoreIds порожній список — доступ до всіх філій мережі
     */
    public function __construct(
        public string $supplierId,
        public string $name,
        public bool $active = true,
        array $allowedStoreIds = [],
    ) {
        $this->allowedStoreIds = array_values($allowedStoreIds);
    }

    public function hasAccessTo(string $storeId): bool
    {
        return [] === $this->allowedStoreIds || \in_array($storeId, $this->allowedStoreIds, true);
    }
}
