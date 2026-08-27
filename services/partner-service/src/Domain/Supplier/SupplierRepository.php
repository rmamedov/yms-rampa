<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

/**
 * Порт сховища постачальників.
 *
 * Індекси (розділ 10.4): unique partial `{edrpou:1}` де edrpou≠null;
 * `{name:1}` для пошуку в адмінці.
 */
interface SupplierRepository
{
    public function save(Supplier $supplier): void;

    public function findById(string $id): ?Supplier;

    /** SUP-01: назва унікальна (порівняння без урахування регістру). */
    public function findByName(string $name): ?Supplier;

    /** SUP-01: ЄДРПОУ унікальний серед неархівованих постачальників. */
    public function findByEdrpou(string $edrpou): ?Supplier;

    /**
     * @return list<Supplier>
     */
    public function search(
        ?string $query = null,
        ?SupplierStatus $status = null,
        int $limit = 50,
        int $offset = 0,
    ): array;

    public function count(?string $query = null, ?SupplierStatus $status = null): int;

    public function remove(string $id): void;
}
