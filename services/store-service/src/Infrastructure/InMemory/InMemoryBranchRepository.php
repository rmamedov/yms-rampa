<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchCriteria;
use App\Domain\Branch\BranchPage;
use App\Domain\Branch\BranchRepository;

/**
 * Реалізація довідника філій у памʼяті. Використовується в юніт-тестах і в dev-режимі,
 * поки MongoDB не піднято; семантика фільтрів і сортування повторює Mongo-реалізацію.
 */
final class InMemoryBranchRepository implements BranchRepository
{
    /** @var array<string, Branch> */
    private array $branches = [];

    /**
     * @param list<Branch> $branches
     */
    public function __construct(array $branches = [])
    {
        $this->saveAll($branches);
    }

    public function save(Branch $branch): void
    {
        $this->branches[$branch->id()] = $branch;
    }

    public function saveAll(array $branches): void
    {
        foreach ($branches as $branch) {
            $this->save($branch);
        }
    }

    public function find(string $branchId): ?Branch
    {
        return $this->branches[$branchId] ?? null;
    }

    public function findByExternalId(string $externalId): ?Branch
    {
        foreach ($this->branches as $branch) {
            if ($branch->externalId() === $externalId) {
                return $branch;
            }
        }

        return null;
    }

    public function findAll(): array
    {
        return array_values($this->branches);
    }

    public function search(BranchCriteria $criteria): BranchPage
    {
        $matched = array_values(array_filter(
            $this->branches,
            static fn (Branch $b): bool => $criteria->matches($b),
        ));

        usort($matched, static fn (Branch $a, Branch $b): int => self::compare($a, $b, $criteria));

        $total = \count($matched);
        $items = \array_slice($matched, $criteria->offset(), $criteria->perPage);

        return new BranchPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function cities(BranchCriteria $criteria): array
    {
        $counts = [];

        foreach ($this->branches as $branch) {
            if (!$criteria->matches($branch)) {
                continue;
            }

            $city = $branch->city();

            // Порожнє місто ламає екран вибору міста в supplier-web (fixtures/README.md).
            if ('' === trim($city)) {
                continue;
            }

            $counts[$city] = ($counts[$city] ?? 0) + 1;
        }

        uksort($counts, static fn (string $a, string $b): int => self::collate($a, $b));

        return array_map(
            static fn (string $city, int $count): array => ['city' => $city, 'storeCount' => $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    public function count(): int
    {
        return \count($this->branches);
    }

    /** STL-05: за замовчуванням сортування за містом, потім за externalId. */
    private static function compare(Branch $a, Branch $b, BranchCriteria $criteria): int
    {
        $direction = 'desc' === $criteria->sortDirection ? -1 : 1;

        $result = match ($criteria->sortBy) {
            'externalId' => self::compareExternalId($a, $b),
            'ymsStatus' => self::collate($a->ymsStatus()->value, $b->ymsStatus()->value),
            'address' => self::collate($a->effectiveAddress(), $b->effectiveAddress()),
            'syncedAt' => $a->syncedAt() <=> $b->syncedAt(),
            default => self::collate($a->city(), $b->city()),
        };

        if (0 === $result) {
            $result = self::compareExternalId($a, $b);

            return $result;
        }

        return $direction * $result;
    }

    private static function compareExternalId(Branch $a, Branch $b): int
    {
        return self::collate($a->externalId(), $b->externalId());
    }

    /** Порівняння рядків з підтримкою кирилиці без залежності від локалі. */
    private static function collate(string $a, string $b): int
    {
        return strcmp($a, $b) <=> 0;
    }
}
