<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\PeriodBucket;
use App\Domain\Stats\DailyStoreStats;
use App\Domain\Stats\DailyStoreStatsRepository;

/**
 * MongoDB-реалізація агрегату «магазин × доба» (колекція daily_store_stats).
 */
final readonly class MongoDailyStoreStatsRepository implements DailyStoreStatsRepository
{
    public const COLLECTION = 'daily_store_stats';

    public function __construct(
        private MongoConnection $connection,
        private DailyStoreStatsDocumentMapper $mapper = new DailyStoreStatsDocumentMapper(),
    ) {
    }

    public function save(DailyStoreStats $stats): void
    {
        $this->saveMany([$stats]);
    }

    public function saveMany(iterable $stats): void
    {
        /** @var class-string $bulkWriteClass */
        $bulkWriteClass = 'MongoDB\Driver\BulkWrite';
        $bulk = new $bulkWriteClass();
        $count = 0;

        foreach ($stats as $item) {
            $bulk->update(
                ['_id' => $item->id()],
                ['$set' => BsonCodec::encode($this->mapper->toDocument($item))],
                ['upsert' => true],
            );
            ++$count;
        }

        if ($count === 0) {
            return;
        }

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespaceFor(self::COLLECTION),
            $bulk,
        );
    }

    public function find(string $storeId, string $date): ?DailyStoreStats
    {
        $documents = $this->query(['_id' => $storeId . ':' . $date], ['limit' => 1]);

        return $documents === [] ? null : $this->mapper->fromDocument($documents[0]);
    }

    public function findByQuery(AnalyticsQuery $query): array
    {
        $filter = [
            'date' => [
                '$gte' => PeriodBucket::day($query->from),
                '$lte' => PeriodBucket::day($query->to),
            ],
        ];

        if ($query->cities !== []) {
            $filter['city'] = ['$in' => $query->cities];
        }
        if ($query->storeIds !== []) {
            $filter['storeId'] = ['$in' => $query->storeIds];
        }

        $documents = $this->query($filter, ['sort' => ['date' => 1, 'storeId' => 1]]);

        return array_map(fn (array $doc): DailyStoreStats => $this->mapper->fromDocument($doc), $documents);
    }

    public function lastRecalculatedAt(): ?\DateTimeImmutable
    {
        $documents = $this->query([], ['sort' => ['recalculatedAt' => -1], 'limit' => 1]);

        return $documents === [] ? null : BsonCodec::decodeDate($documents[0]['recalculatedAt'] ?? null);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    private function query(array $filter, array $options = []): array
    {
        /** @var class-string $queryClass */
        $queryClass = 'MongoDB\Driver\Query';
        $mongoQuery = new $queryClass(BsonCodec::encode($filter), $options);

        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespaceFor(self::COLLECTION),
            $mongoQuery,
        );
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        /** @var list<array<string, mixed>> $documents */
        $documents = $cursor->toArray();

        return $documents;
    }
}
