<?php

declare(strict_types=1);

namespace App\Domain\Branch;

/**
 * Сховище довідника філій. Домен знає лише інтерфейс — реалізації живуть
 * у src/Infrastructure/Mongo (прод) і src/Infrastructure/InMemory (dev і тести).
 */
interface BranchRepository
{
    public function save(Branch $branch): void;

    /**
     * @param list<Branch> $branches
     */
    public function saveAll(array $branches): void;

    public function find(string $branchId): ?Branch;

    public function findByExternalId(string $externalId): ?Branch;

    /** @return list<Branch> */
    public function findAll(): array;

    /** Серверна фільтрація + пагінація + сортування (STL-02, STL-03, STL-05). */
    public function search(BranchCriteria $criteria): BranchPage;

    /**
     * Список міст довідника (STL-02, supplier-web «міста → філії»).
     *
     * @return list<array{city: string, storeCount: int}>
     */
    public function cities(BranchCriteria $criteria): array;

    public function count(): int;
}
