<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchCriteria;
use App\Domain\Branch\BranchPage;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\YmsStatus;
use App\Infrastructure\Mongo\Mapper\BranchDocumentMapper;

/**
 * Реалізація довідника філій на MongoDB, колекція `branches` (10.2.1).
 *
 * Індекси створює MongoIndexInitializer (команда yms:mongo:init).
 */
final readonly class MongoBranchRepository implements BranchRepository
{
    public const string COLLECTION = 'branches';

    public function __construct(
        private MongoConnection $connection,
    ) {
    }

    public function save(Branch $branch): void
    {
        $this->connection->upsert(self::COLLECTION, $branch->id(), BranchDocumentMapper::toDocument($branch));
    }

    public function saveAll(array $branches): void
    {
        $documents = [];

        foreach ($branches as $branch) {
            $documents[$branch->id()] = BranchDocumentMapper::toDocument($branch);
        }

        $this->connection->bulkUpsert(self::COLLECTION, $documents);
    }

    public function find(string $branchId): ?Branch
    {
        $documents = $this->connection->find(self::COLLECTION, ['_id' => $branchId], ['limit' => 1]);

        return [] === $documents ? null : BranchDocumentMapper::fromDocument($documents[0]);
    }

    public function findByExternalId(string $externalId): ?Branch
    {
        $documents = $this->connection->find(self::COLLECTION, ['externalId' => $externalId], ['limit' => 1]);

        return [] === $documents ? null : BranchDocumentMapper::fromDocument($documents[0]);
    }

    public function findAll(): array
    {
        return array_map(
            BranchDocumentMapper::fromDocument(...),
            $this->connection->find(self::COLLECTION, [], ['sort' => ['city' => 1, 'externalId' => 1]]),
        );
    }

    public function search(BranchCriteria $criteria): BranchPage
    {
        $filter = $this->filter($criteria);
        $total = $this->connection->countDocuments(self::COLLECTION, $filter);

        $documents = $this->connection->find(self::COLLECTION, $filter, [
            'sort' => $this->sort($criteria),
            'skip' => $criteria->offset(),
            'limit' => $criteria->perPage,
        ]);

        return new BranchPage(
            array_map(BranchDocumentMapper::fromDocument(...), $documents),
            $total,
            $criteria->page,
            $criteria->perPage,
        );
    }

    public function cities(BranchCriteria $criteria): array
    {
        $filter = $this->filter($criteria);
        $filter['city'] = ['$nin' => [null, '']];

        $rows = $this->connection->aggregate(self::COLLECTION, [
            ['$match' => $filter],
            ['$group' => ['_id' => '$city', 'storeCount' => ['$sum' => 1]]],
            ['$sort' => ['_id' => 1]],
        ]);

        return array_map(
            static fn (array $row): array => [
                'city' => (string) $row['_id'],
                'storeCount' => (int) ($row['storeCount'] ?? 0),
            ],
            $rows,
        );
    }

    public function count(): int
    {
        return $this->connection->countDocuments(self::COLLECTION);
    }

    /**
     * @return array<string, mixed>
     */
    private function filter(BranchCriteria $criteria): array
    {
        $filter = [];

        if ([] !== $criteria->cities) {
            $named = array_values(array_filter(
                $criteria->cities,
                static fn (string $city): bool => BranchCriteria::CITY_NONE !== $city,
            ));

            // CITY_NONE — філії без міста. У Mongo це і порожній рядок, і
            // відсутнє поле: `$in: [null]` покриває обидва випадки.
            if (\count($named) !== \count($criteria->cities)) {
                $named[] = '';
                $named[] = null;
            }

            $filter['city'] = ['$in' => $named];
        }

        if ([] !== $criteria->statuses) {
            $filter['ymsStatus'] = ['$in' => array_map(
                static fn (YmsStatus $s): string => $s->value,
                $criteria->statuses,
            )];
        }

        if (null !== $criteria->visibleToSuppliers) {
            $filter['visibleToSuppliers'] = $criteria->visibleToSuppliers;
        }

        if (null !== $criteria->eligibleOnly) {
            $filter['ineligibilityReasons'] = $criteria->eligibleOnly
                ? ['$size' => 0]
                : ['$not' => ['$size' => 0]];
        }

        // Обидва предикати лягають на `_id`, тому обʼєднуються через $and,
        // інакше другий перетер би перший.
        $idPredicates = [];

        // RBAC-17: скоуп-предикат виконує СХОВИЩЕ, а не пост-фільтрація в памʼяті.
        // Порожній перелік дає `$in: []` — вибірка гарантовано порожня (RBAC-13).
        if (null !== $criteria->scopedStoreIds) {
            $idPredicates[] = ['_id' => ['$in' => $criteria->scopedStoreIds]];
        }

        if (null !== $criteria->configured && null !== $criteria->configuredStoreIds) {
            $idPredicates[] = ['_id' => $criteria->configured
                ? ['$in' => $criteria->configuredStoreIds]
                : ['$nin' => $criteria->configuredStoreIds]];
        }

        if ([] !== $idPredicates) {
            $filter['$and'] = $idPredicates;
        }

        $query = trim((string) $criteria->query);

        if ('' !== $query) {
            // STL-03: externalId — точний/префіксний збіг, адреса — підрядок без регістру.
            $escaped = preg_quote($query, '/');
            $filter['$or'] = [
                ['externalId' => ['$regex' => '^'.$escaped, '$options' => 'i']],
                ['address' => ['$regex' => $escaped, '$options' => 'i']],
                ['addressOverride' => ['$regex' => $escaped, '$options' => 'i']],
            ];
        }

        return $filter;
    }

    /**
     * @return array<string, int>
     */
    private function sort(BranchCriteria $criteria): array
    {
        $direction = 'desc' === $criteria->sortDirection ? -1 : 1;

        return match ($criteria->sortBy) {
            'externalId' => ['externalId' => $direction],
            'ymsStatus' => ['ymsStatus' => $direction, 'externalId' => 1],
            'address' => ['address' => $direction, 'externalId' => 1],
            'syncedAt' => ['syncedAt' => $direction, 'externalId' => 1],
            // STL-05: за замовчуванням — місто, потім externalId.
            default => ['city' => $direction, 'externalId' => 1],
        };
    }
}
