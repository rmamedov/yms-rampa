<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\ReservedSlotRuleRepository;

/**
 * Сховище правил резервів у памʼяті.
 */
final class InMemoryReservedSlotRuleRepository implements ReservedSlotRuleRepository
{
    /** @var array<string, ReservedSlotRule> */
    private array $rules = [];

    public function save(ReservedSlotRule $rule): void
    {
        $this->rules[$rule->id] = $rule;
    }

    public function find(string $id): ?ReservedSlotRule
    {
        return $this->rules[$id] ?? null;
    }

    public function findForStore(string $storeId, ?bool $activeOnly = null): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (ReservedSlotRule $r): bool => $r->storeId === $storeId
                && (null === $activeOnly || $r->active === $activeOnly),
        ));
    }

    public function findForSupplier(string $supplierId): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (ReservedSlotRule $r): bool => $r->supplierId === $supplierId,
        ));
    }

    public function delete(string $id): void
    {
        unset($this->rules[$id]);
    }
}
