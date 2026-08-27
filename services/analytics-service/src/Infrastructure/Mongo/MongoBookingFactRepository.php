<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Fact\BookingFact;
use App\Domain\Fact\BookingFactRepository;

/**
 * MongoDB-реалізація сховища фактів бронювань (колекція booking_facts).
 *
 * Використовує низькорівневий драйвер ext-mongodb напряму, без бібліотеки
 * mongodb/mongodb; усі класи драйвера створюються рефлексивно через рядки
 * класів, щоб відсутність розширення не ламала автозавантаження і тести.
 */
final readonly class MongoBookingFactRepository implements BookingFactRepository
{
    public const COLLECTION = 'booking_facts';

    public function __construct(
        private MongoConnection $connection,
        private BookingFactDocumentMapper $mapper = new BookingFactDocumentMapper(),
    ) {
    }

    public function findByBookingId(string $bookingId): ?BookingFact
    {
        $documents = $this->execute(['_id' => $bookingId], ['limit' => 1]);

        return $documents === [] ? null : $this->mapper->fromDocument($documents[0]);
    }

    public function save(BookingFact $fact): void
    {
        $document = BsonCodec::encode($this->mapper->toDocument($fact));

        /** @var class-string $bulkWriteClass */
        $bulkWriteClass = 'MongoDB\Driver\BulkWrite';
        $bulk = new $bulkWriteClass();
        $bulk->update(['_id' => $fact->bookingId], ['$set' => $document], ['upsert' => true]);

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespaceFor(self::COLLECTION),
            $bulk,
        );
    }

    public function findByQuery(AnalyticsQuery $query): array
    {
        $documents = $this->execute($this->buildFilter($query), ['sort' => ['slotStart' => 1]]);

        return array_map(fn (array $doc): BookingFact => $this->mapper->fromDocument($doc), $documents);
    }

    public function lastUpdatedAt(): ?\DateTimeImmutable
    {
        $documents = $this->execute([], ['sort' => ['updatedAt' => -1], 'limit' => 1]);

        return $documents === [] ? null : BsonCodec::decodeDate($documents[0]['updatedAt'] ?? null);
    }

    public function countAll(): int
    {
        /** @var class-string $commandClass */
        $commandClass = 'MongoDB\Driver\Command';
        $command = new $commandClass(['count' => self::COLLECTION]);

        $cursor = $this->connection->manager()->executeCommand($this->connection->database(), $command);
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        /** @var list<array<string, mixed>> $result */
        $result = $cursor->toArray();

        return (int) ($result[0]['n'] ?? 0);
    }

    /**
     * Побудова Mongo-фільтра з фільтрів дашборда (ANL-10). Період — напівінтервал
     * [from; to) за slotStart, як і в доменній перевірці AnalyticsQuery.
     *
     * @return array<string, mixed>
     */
    public function buildFilter(AnalyticsQuery $query): array
    {
        $filter = [
            'slotStart' => ['$gte' => $query->from, '$lt' => $query->to],
        ];

        if ($query->cities !== []) {
            $filter['city'] = ['$in' => $query->cities];
        }
        if ($query->storeIds !== []) {
            $filter['storeId'] = ['$in' => $query->storeIds];
        }
        if ($query->supplierIds !== []) {
            $filter['supplierId'] = ['$in' => $query->supplierIds];
        }
        if ($query->rampIds !== []) {
            $filter['rampId'] = ['$in' => $query->rampIds];
        }
        if ($query->types !== []) {
            $filter['type'] = ['$in' => array_map(static fn (BookingType $t): string => $t->value, $query->types)];
        }
        if ($query->statuses !== []) {
            $filter['status'] = ['$in' => array_map(static fn (BookingStatus $s): string => $s->value, $query->statuses)];
        }

        return $filter;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    private function execute(array $filter, array $options = []): array
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
