<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

/**
 * Сховище правил резервування слотів (10.2.3).
 */
interface ReservedSlotRuleRepository
{
    public function save(ReservedSlotRule $rule): void;

    public function find(string $id): ?ReservedSlotRule;

    /** @return list<ReservedSlotRule> */
    public function findForStore(string $storeId, ?bool $activeOnly = null): array;

    /** @return list<ReservedSlotRule> */
    public function findForSupplier(string $supplierId): array;

    public function delete(string $id): void;
}
