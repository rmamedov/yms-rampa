<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Supplier\Supplier;
use App\Domain\Supplier\SupplierRepository;
use App\Domain\Supplier\SupplierStatus;

/**
 * Реалізація сховища постачальників у пам'яті.
 *
 * Живе в src/ (не в tests/), щоб її можна було використовувати і в dev-режимі
 * на машині без MongoDB, і в юніт-тестах.
 */
final class InMemorySupplierRepository implements SupplierRepository
{
    /** @var array<string, Supplier> */
    private array $items = [];

    public function save(Supplier $supplier): void
    {
        $this->items[$supplier->id()] = $supplier;
    }

    public function findById(string $id): ?Supplier
    {
        return $this->items[$id] ?? null;
    }

    public function findByName(string $name): ?Supplier
    {
        $needle = mb_strtolower(trim($name), 'UTF-8');

        foreach ($this->items as $supplier) {
            if (null !== $supplier->archivedAt()) {
                continue;
            }

            if (mb_strtolower($supplier->name(), 'UTF-8') === $needle) {
                return $supplier;
            }
        }

        return null;
    }

    public function findByEdrpou(string $edrpou): ?Supplier
    {
        foreach ($this->items as $supplier) {
            if (null !== $supplier->archivedAt()) {
                continue;
            }

            if ($supplier->edrpou() === $edrpou) {
                return $supplier;
            }
        }

        return null;
    }

    public function search(
        ?string $query = null,
        ?SupplierStatus $status = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $found = $this->filter($query, $status);

        usort($found, static fn (Supplier $a, Supplier $b): int => strcmp($a->name(), $b->name()));

        return array_values(\array_slice($found, $offset, $limit));
    }

    public function count(?string $query = null, ?SupplierStatus $status = null): int
    {
        return \count($this->filter($query, $status));
    }

    public function remove(string $id): void
    {
        unset($this->items[$id]);
    }

    /**
     * @return list<Supplier>
     */
    private function filter(?string $query, ?SupplierStatus $status): array
    {
        $needle = null === $query ? null : mb_strtolower(trim($query), 'UTF-8');
        $found = [];

        foreach ($this->items as $supplier) {
            if (null !== $supplier->archivedAt()) {
                continue;
            }

            if (null !== $status && $supplier->status() !== $status) {
                continue;
            }

            if (null !== $needle && '' !== $needle) {
                $haystack = mb_strtolower($supplier->name().' '.($supplier->edrpou() ?? ''), 'UTF-8');

                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $found[] = $supplier;
        }

        return $found;
    }
}
