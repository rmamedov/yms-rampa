<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

use App\Domain\Shared\ValidationException;

/**
 * Прив'язка постачальника до магазинів (SUP-03).
 *
 * Два режими: «всі магазини» або whitelist конкретних філій.
 * У режимі whitelist постачальник бачить у supplier-web лише філії з переліку,
 * які водночас `ymsStatus=active` і видимі постачальникам (STC-04) —
 * останні дві умови перевіряє store-service, тут зберігається лише дозвіл.
 */
final readonly class StoreAccess
{
    /**
     * @param list<string> $storeIds
     */
    private function __construct(
        public bool $allStores,
        public array $storeIds,
    ) {
    }

    public static function allStores(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $storeIds
     */
    public static function whitelist(array $storeIds): self
    {
        $clean = [];

        foreach ($storeIds as $storeId) {
            $storeId = trim((string) $storeId);

            if ('' === $storeId) {
                continue;
            }

            $clean[$storeId] = true;
        }

        if ([] === $clean) {
            throw new ValidationException(
                'У режимі whitelist потрібно вказати щонайменше один магазин.',
                'SUPPLIER_STORE_WHITELIST_EMPTY',
            );
        }

        $ids = array_keys($clean);
        sort($ids);

        return new self(false, $ids);
    }

    /**
     * Чи дозволено постачальнику працювати з цією філією.
     */
    public function allows(string $storeId): bool
    {
        return $this->allStores || \in_array($storeId, $this->storeIds, true);
    }

    /**
     * @return array{allStores: bool, storeIds: list<string>}
     */
    public function toArray(): array
    {
        return [
            'allStores' => $this->allStores,
            'storeIds' => $this->storeIds,
        ];
    }
}
