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
use MongoDB\Driver\Query;
use MongoDB\Driver\Session;

/**
 * Transactional outbox у MongoDB (розділ 10.3.3, DATA-16).
 *
 * Запис виконується в тій самій сесії/транзакції, що й зміна бронювання;
 * публікація в RabbitMQ — окремим релеєм з семантикою at-least-once.
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
        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespace(self::COLLECTION),
            new Query(
                ['publishedAt' => null],
                ['sort' => ['occurredAt' => 1], 'limit' => $limit],
            ),
        );

        $records = [];

        foreach ($cursor as $document) {
            $document = (array) $document;
            $occurredAt = $document['occurredAt'];

            $records[] = new OutboxRecord(
                id: (string) $document['_id'],
                event: new DomainEvent(
                    type: EventType::from((string) $document['eventType']),
                    aggregateType: (string) $document['aggregateType'],
                    aggregateId: (string) $document['aggregateId'],
                    payload: json_decode(json_encode($document['payload'] ?? [], \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR),
                    occurredAt: $occurredAt instanceof UTCDateTime
                        ? $occurredAt->toDateTimeImmutable()->setTimezone(new DateTimeZone('UTC'))
                        : new DateTimeImmutable((string) $occurredAt, new DateTimeZone('UTC')),
                ),
                attempts: (int) ($document['attempts'] ?? 0),
            );
        }

        return $records;
    }

    public function markPublished(string $recordId, DateTimeImmutable $publishedAt): void
    {
        $bulk = new BulkWrite();
        $bulk->update(
            ['_id' => new ObjectId($recordId)],
            [
                '$set' => ['publishedAt' => new UTCDateTime($publishedAt->getTimestamp() * 1000)],
                '$inc' => ['attempts' => 1],
            ],
        );

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespace(self::COLLECTION),
            $bulk,
        );
    }
}
