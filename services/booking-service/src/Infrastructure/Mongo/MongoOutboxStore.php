<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Outbox\OutboxRecord;
use App\Domain\Outbox\OutboxStore;
use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Query;
use MongoDB\Driver\Session;

/**
 * Transactional outbox у MongoDB (розділ 10.3.3, DATA-16).
 *
 * Запис виконується в тій самій сесії/транзакції, що й зміна бронювання;
 * публікація — окремим релеєм (yms:outbox:relay) з семантикою at-least-once.
 *
 * Три стани документа: у черзі (publishedAt = failedAt = null), опублікований
 * (publishedAt) і в карантині (failedAt + failureReason) — див. OutboxRecord.
 */
final readonly class MongoOutboxStore implements OutboxStore
{
    public const string COLLECTION = 'outbox';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function append(array $events): void
    {
        if ([] === $events) {
            return;
        }

        $this->connection->transactional(function (?Session $session) use ($events): void {
            $this->appendInSession($events, $session);
        });
    }

    /**
     * Варіант для виклику зсередини вже відкритої транзакції репозиторію.
     *
     * @param list<DomainEvent> $events
     */
    public function appendInSession(array $events, ?Session $session): void
    {
        if ([] === $events) {
            return;
        }

        $bulk = new BulkWrite();

        foreach ($events as $event) {
            $bulk->insert([
                '_id' => new ObjectId(),
                'aggregateType' => $event->aggregateType,
                'aggregateId' => $event->aggregateId,
                'eventType' => $event->type->value,
                'payload' => $event->payload,
                'occurredAt' => new UTCDateTime($event->occurredAt->getTimestamp() * 1000),
                'publishedAt' => null,
                'attempts' => 0,
                'failedAt' => null,
                'failureReason' => null,
            ]);
        }

        $options = null === $session ? [] : ['session' => $session];
        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespace(self::COLLECTION),
            $bulk,
            $options,
        );
    }

    public function pending(int $limit = 100): array
    {
        // failedAt: null ловить і документи, записані до появи карантину, —
        // у Mongo відсутнє поле дорівнює null у фільтрі рівності.
        return $this->query(['publishedAt' => null, 'failedAt' => null], $limit);
    }

    public function quarantined(int $limit = 100): array
    {
        return $this->query(['failedAt' => ['$ne' => null]], $limit);
    }

    public function countQuarantined(): int
    {
        $cursor = $this->connection->manager()->executeCommand(
            $this->connection->database(),
            new Command([
                'count' => self::COLLECTION,
                'query' => ['failedAt' => ['$ne' => null]],
            ]),
        );

        $result = current($cursor->toArray());

        return false === $result ? 0 : (int) ($result->n ?? 0);
    }

    public function markPublished(string $recordId, DateTimeImmutable $publishedAt): void
    {
        $this->update($recordId, [
            '$set' => ['publishedAt' => new UTCDateTime($publishedAt->getTimestamp() * 1000)],
            '$inc' => ['attempts' => 1],
        ]);
    }

    public function markFailed(string $recordId, string $reason, DateTimeImmutable $failedAt): void
    {
        $this->update($recordId, [
            '$set' => [
                'failedAt' => new UTCDateTime($failedAt->getTimestamp() * 1000),
                'failureReason' => $reason,
            ],
            '$inc' => ['attempts' => 1],
        ]);
    }

    public function requeueFailed(): int
    {
        $bulk = new BulkWrite();
        // attempts свідомо не скидається: лічильник має накопичуватися.
        $bulk->update(
            ['failedAt' => ['$ne' => null]],
            ['$set' => ['failedAt' => null, 'failureReason' => null]],
            ['multi' => true],
        );

        $result = $this->connection->manager()->executeBulkWrite(
            $this->connection->namespace(self::COLLECTION),
            $bulk,
        );

        return $result->getModifiedCount();
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<OutboxRecord>
     */
    private function query(array $filter, int $limit): array
    {
        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespace(self::COLLECTION),
            new Query($filter, ['sort' => ['occurredAt' => 1], 'limit' => $limit]),
        );

        $records = [];

        foreach ($cursor as $document) {
            $document = (array) $document;
            $failedAt = $document['failedAt'] ?? null;
            $failureReason = $document['failureReason'] ?? null;

            $records[] = new OutboxRecord(
                id: (string) $document['_id'],
                event: new DomainEvent(
                    type: EventType::from((string) $document['eventType']),
                    aggregateType: (string) $document['aggregateType'],
                    aggregateId: (string) $document['aggregateId'],
                    payload: json_decode(json_encode($document['payload'] ?? [], \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR),
                    occurredAt: self::toDate($document['occurredAt']),
                ),
                attempts: (int) ($document['attempts'] ?? 0),
                failedAt: null === $failedAt ? null : self::toDate($failedAt),
                failureReason: \is_string($failureReason) ? $failureReason : null,
            );
        }

        return $records;
    }

    /** @param array<string, mixed> $update */
    private function update(string $recordId, array $update): void
    {
        $bulk = new BulkWrite();
        $bulk->update(['_id' => new ObjectId($recordId)], $update);

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespace(self::COLLECTION),
            $bulk,
        );
    }

    private static function toDate(mixed $value): DateTimeImmutable
    {
        return $value instanceof UTCDateTime
            ? $value->toDateTimeImmutable()->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
