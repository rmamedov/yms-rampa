<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Booking\Exception\SupplierNotAllowedException;
use App\Domain\Supplier\SupplierDirectory;
use App\Domain\Supplier\SupplierInfo;

/**
 * Довідник постачальників у памʼяті. У проді замінюється HTTP-клієнтом
 * до partner-service; контракт перевірки BOOK-02 однаковий.
 */
final class InMemorySupplierDirectory implements SupplierDirectory
{
    /** @var array<string, SupplierInfo> */
    private array $suppliers = [];

    /**
     * @param list<SupplierInfo> $suppliers
     */
    public function __construct(array $suppliers = [])
    {
        foreach ($suppliers as $supplier) {
            $this->suppliers[$supplier->supplierId] = $supplier;
        }
    }

    public function add(SupplierInfo $supplier): void
    {
        $this->suppliers[$supplier->supplierId] = $supplier;
    }

    public function find(string $supplierId): ?SupplierInfo
    {
        return $this->suppliers[$supplierId] ?? null;
    }

    public function listForStore(string $storeId): array
    {
        $allowed = array_values(array_filter(
            $this->suppliers,
            static fn (SupplierInfo $supplier): bool => $supplier->active && $supplier->hasAccessTo($storeId),
        ));

        // Порядок детермінований і не залежить від локалі процесу: у списку
        // вибору важлива стабільність, а не тонкощі української колації.
        usort($allowed, static fn (SupplierInfo $a, SupplierInfo $b): int => strcmp($a->name, $b->name));

        return $allowed;
    }

    public function assertMayBookAt(string $supplierId, string $storeId): SupplierInfo
    {
        $supplier = $this->find($supplierId);

        if (null === $supplier) {
            throw new SupplierNotAllowedException($supplierId, $storeId, 'Постачальника не знайдено');
        }

        if (!$supplier->active) {
            throw SupplierNotAllowedException::suspended($supplierId, $storeId);
        }

        if (!$supplier->hasAccessTo($storeId)) {
            throw new SupplierNotAllowedException($supplierId, $storeId);
        }

        return $supplier;
    }
}
