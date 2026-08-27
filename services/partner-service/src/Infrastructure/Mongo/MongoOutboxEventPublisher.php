<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventPublisher;

/**
 * Публікація доменних подій через outbox-колекцію (DATA-16).
 *
 * Подія лягає в `partners.outbox`, звідки релей на Symfony Messenger
 * відправляє її в RabbitMQ з семантикою at-least-once. Допустимі значення
 * `eventType` — лише канонічні події реєстру; partner-service публікує
 * DriverCreated і SupplierSuspended.
 *
 * Обмеження поточної реалізації: запис документа і запис у outbox поки що
 * не обгорнуті в одну транзакцію MongoDB (для цього потрібен replica set).
 */
final readonly class MongoOutboxEventPublisher implements EventPublisher
{
    public const COLLECTION = 'outbox';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        if ([] === $events) {
            return;
        }

        $bulk = new \MongoDB\Driver\BulkWrite();

        foreach ($events as $event) {
            $bulk->insert([
                'eventType' => $event->eventType(),
                'aggregateId' => $event->aggregateId(),
                'payload' => $event->payload(),
                'occurredAt' => MongoCodec::toBson($event->occurredAt()),
                'publishedAt' => null,
                'attempts' => 0,
                'schemaVersion' => 1,
            ]);
        }

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespaceFor(self::COLLECTION),
            $bulk,
        );
    }
}
