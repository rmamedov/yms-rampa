<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Infrastructure\Mongo\Mapper\ConfigurationDocumentMapper;

/**
 * Версіонована конфігурація магазину в колекції `store_configs` (10.2.2).
 */
final readonly class MongoStoreConfigurationRepository implements StoreConfigurationRepository
{
    public const string COLLECTION = 'store_configs';

    public function __construct(
        private MongoConnection $connection,
    ) {
    }

    public function save(StoreConfiguration $configuration): void
    {
        $this->connection->upsert(
            self::COLLECTION,
            $configuration->id,
            ConfigurationDocumentMapper::configToDocument($configuration),
        );
    }

    public function find(string $id): ?StoreConfiguration
    {
        $documents = $this->connection->find(self::COLLECTION, ['_id' => $id], ['limit' => 1]);

        return [] === $documents ? null : ConfigurationDocumentMapper::configFromDocument($documents[0]);
    }

    public function findAllForStore(string $storeId): array
    {
        return array_map(
            ConfigurationDocumentMapper::configFromDocument(...),
            $this->connection->find(
                self::COLLECTION,
                ['storeId' => $storeId, 'archivedAt' => null],
                ['sort' => ['version' => -1]],
            ),
        );
    }

    public function findEffectiveAt(string $storeId, \DateTimeImmutable $at): ?StoreConfiguration
    {
        $documents = $this->connection->find(
            self::COLLECTION,
            [
                'storeId' => $storeId,
                'archivedAt' => null,
                'effectiveFrom' => ['$lte' => MongoConnection::fromDateTime($at)],
            ],
            ['sort' => ['effectiveFrom' => -1, 'version' => -1], 'limit' => 1],
        );

        return [] === $documents ? null : ConfigurationDocumentMapper::configFromDocument($documents[0]);
    }

    public function findLatest(string $storeId): ?StoreConfiguration
    {
        return $this->findAllForStore($storeId)[0] ?? null;
    }

    public function nextVersion(string $storeId): int
    {
        return ($this->findLatest($storeId)?->version ?? 0) + 1;
    }

    public function configuredStoreIds(\DateTimeImmutable $at): array
    {
        $rows = $this->connection->aggregate(self::COLLECTION, [
            ['$match' => ['archivedAt' => null, 'effectiveFrom' => ['$lte' => MongoConnection::fromDateTime($at)]]],
            ['$sort' => ['effectiveFrom' => -1, 'version' => -1]],
            ['$group' => ['_id' => '$storeId', 'doc' => ['$first' => '$$ROOT']]],
        ]);

        $result = [];

        foreach ($rows as $row) {
            $document = $row['doc'] ?? null;

            if (!\is_array($document)) {
                continue;
            }

            /** @var array<string, mixed> $document */
            if (ConfigurationDocumentMapper::configFromDocument($document)->isComplete()) {
                $result[] = (string) $row['_id'];
            }
        }

        return $result;
    }
}
