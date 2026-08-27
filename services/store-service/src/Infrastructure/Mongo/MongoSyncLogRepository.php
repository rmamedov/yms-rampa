<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Sync\SyncLogEntry;
use App\Domain\Sync\SyncLogRepository;
use App\Domain\Sync\SyncStatus;
use App\Domain\Sync\SyncTrigger;

/**
 * Журнал синхронізацій у колекції `sync_log` (10.2.3, INT-11).
 * TTL-індекс на startedAt — 180 днів (створюється MongoIndexInitializer).
 */
final readonly class MongoSyncLogRepository implements SyncLogRepository
{
    public const string COLLECTION = 'sync_log';

    public function __construct(
        private MongoConnection $connection,
    ) {
    }

    public function save(SyncLogEntry $entry): void
    {
        $this->connection->upsert(self::COLLECTION, $entry->id, [
            '_id' => $entry->id,
            'status' => $entry->status->value,
            'trigger' => $entry->trigger->value,
            'initiator' => $entry->initiator,
            'source' => $entry->source,
            'startedAt' => MongoConnection::fromDateTime($entry->startedAt),
            'finishedAt' => MongoConnection::fromDateTime($entry->finishedAt),
            'fetched' => $entry->fetched,
            'created' => $entry->created,
            'updated' => $entry->updated,
            'missing' => $entry->missing,
            'archived' => $entry->archived,
            'conflicts' => $entry->conflicts,
            'skipped' => $entry->skipped,
            'errors' => $entry->errors,
        ]);
    }

    public function find(string $id): ?SyncLogEntry
    {
        $documents = $this->connection->find(self::COLLECTION, ['_id' => $id], ['limit' => 1]);

        return [] === $documents ? null : self::fromDocument($documents[0]);
    }

    public function findRunning(): ?SyncLogEntry
    {
        $documents = $this->connection->find(
            self::COLLECTION,
            ['status' => SyncStatus::Running->value],
            ['limit' => 1],
        );

        return [] === $documents ? null : self::fromDocument($documents[0]);
    }

    public function recent(int $limit, int $offset = 0): array
    {
        return array_map(
            self::fromDocument(...),
            $this->connection->find(self::COLLECTION, [], [
                'sort' => ['startedAt' => -1],
                'skip' => max(0, $offset),
                'limit' => max(1, $limit),
            ]),
        );
    }

    public function count(): int
    {
        return $this->connection->countDocuments(self::COLLECTION);
    }

    public function findLastSuccessful(): ?SyncLogEntry
    {
        $documents = $this->connection->find(
            self::COLLECTION,
            ['status' => ['$in' => [SyncStatus::Success->value, SyncStatus::Partial->value]]],
            ['sort' => ['startedAt' => -1], 'limit' => 1],
        );

        return [] === $documents ? null : self::fromDocument($documents[0]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function fromDocument(array $document): SyncLogEntry
    {
        $errors = [];

        foreach ((array) ($document['errors'] ?? []) as $error) {
            $errors[] = (string) $error;
        }

        return new SyncLogEntry(
            id: (string) $document['_id'],
            status: SyncStatus::tryFrom((string) ($document['status'] ?? '')) ?? SyncStatus::Failed,
            trigger: SyncTrigger::tryFrom((string) ($document['trigger'] ?? '')) ?? SyncTrigger::Cron,
            initiator: isset($document['initiator']) ? (string) $document['initiator'] : null,
            startedAt: MongoConnection::toDateTime($document['startedAt'] ?? null) ?? new \DateTimeImmutable('@0'),
            finishedAt: MongoConnection::toDateTime($document['finishedAt'] ?? null),
            fetched: (int) ($document['fetched'] ?? 0),
            created: (int) ($document['created'] ?? 0),
            updated: (int) ($document['updated'] ?? 0),
            missing: (int) ($document['missing'] ?? 0),
            archived: (int) ($document['archived'] ?? 0),
            conflicts: (int) ($document['conflicts'] ?? 0),
            skipped: (int) ($document['skipped'] ?? 0),
            errors: $errors,
            source: isset($document['source']) ? (string) $document['source'] : null,
        );
    }
}
