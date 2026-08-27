<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Slot\SlotFact;
use App\Domain\Slot\SlotFactRepository;

/**
 * MongoDB-реалізація інвентаря слотів (колекція slot_facts) — джерело
 * слото-хвилин для KPI-01.
 */
final readonly class MongoSlotFactRepository implements SlotFactRepository
{
    public const COLLECTION = 'slot_facts';

    public function __construct(
        private MongoConnection $connection,
        private SlotFactDocumentMapper $mapper = new SlotFactDocumentMapper(),
    ) {
    }

    public function save(SlotFact $slot): void
    {
        $this->saveMany([$slot]);
    }

    public function saveMany(iterable $slots): void
    {
        /** @var class-string $bulkWriteClass */
        $bulkWriteClass = 'MongoDB\Driver\BulkWrite';
        $bulk = new $bulkWriteClass();
        $count = 0;

        foreach ($slots as $slot) {
            $bulk->update(
                ['_id' => $slot->slotId],
                ['$set' => BsonCodec::encode($this->mapper->toDocument($slot))],
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

    public function findByQuery(AnalyticsQuery $query): array
    {
        $filter = ['start' => ['$gte' => $query->from, '$lt' => $query->to]];

        if ($query->cities !== []) {
            $filter['city'] = ['$in' => $query->cities];
        }
        if ($query->storeIds !== []) {
            $filter['storeId'] = ['$in' => $query->storeIds];
        }
        if ($query->rampIds !== []) {
            $filter['rampId'] = ['$in' => $query->rampIds];
        }

        /** @var class-string $queryClass */
        $queryClass = 'MongoDB\Driver\Query';
        $mongoQuery = new $queryClass(BsonCodec::encode($filter), ['sort' => ['start' => 1]]);

        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespaceFor(self::COLLECTION),
            $mongoQuery,
        );
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        /** @var list<array<string, mixed>> $documents */
        $documents = $cursor->toArray();

        return array_map(fn (array $doc): SlotFact => $this->mapper->fromDocument($doc), $documents);
    }

    public function countAll(): int
    {
        /** @var class-string $commandClass */
        $commandClass = 'MongoDB\Driver\Command';
        $cursor = $this->connection->manager()->executeCommand(
            $this->connection->database(),
            new $commandClass(['count' => self::COLLECTION]),
        );
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        /** @var list<array<string, mixed>> $result */
        $result = $cursor->toArray();

        return (int) ($result[0]['n'] ?? 0);
    }
}
