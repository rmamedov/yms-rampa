<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Тонкий шар над драйвером MongoDB (БД `identity_staff`, розділ 10.5).
 *
 * ВАЖЛИВО: розширення ext-mongodb може бути відсутнє на машині розробника.
 * Клас НЕ інстанціюється при завантаженні контейнера (виключений з автоварінгу
 * в config/services.yaml) і перевіряє наявність розширення в рантаймі —
 * тому його присутність у кодовій базі не ламає ані автозавантаження, ані тести.
 */
final class MongoConnection
{
    private \MongoDB\Driver\Manager $manager;

    public function __construct(
        string $uri = 'mongodb://127.0.0.1:27017',
        public readonly string $database = 'identity_staff',
    ) {
        if (!self::isDriverAvailable()) {
            throw new \RuntimeException(
                'Розширення ext-mongodb не встановлено — використайте InMemory-реалізації репозиторіїв.',
            );
        }

        $this->manager = new \MongoDB\Driver\Manager($uri);
    }

    public static function isDriverAvailable(): bool
    {
        return \extension_loaded('mongodb') && class_exists(\MongoDB\Driver\Manager::class);
    }

    public function manager(): \MongoDB\Driver\Manager
    {
        return $this->manager;
    }

    public function namespaceFor(string $collection): string
    {
        return $this->database.'.'.$collection;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    public function find(string $collection, array $filter = [], array $options = []): array
    {
        $cursor = $this->manager->executeQuery(
            $this->namespaceFor($collection),
            new \MongoDB\Driver\Query($filter, $options),
        );
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        /** @var list<array<string, mixed>> $documents */
        $documents = $cursor->toArray();

        return $documents;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    public function findOne(string $collection, array $filter, array $options = []): ?array
    {
        return $this->find($collection, $filter, ['limit' => 1] + $options)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $document
     */
    public function upsert(string $collection, array $filter, array $document): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update($filter, ['$set' => $document], ['upsert' => true]);

        $this->manager->executeBulkWrite($this->namespaceFor($collection), $bulk);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function insert(string $collection, array $document): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->insert($document);

        $this->manager->executeBulkWrite($this->namespaceFor($collection), $bulk);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $set
     */
    public function updateMany(string $collection, array $filter, array $set): int
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update($filter, ['$set' => $set], ['multi' => true]);

        return $this->manager->executeBulkWrite($this->namespaceFor($collection), $bulk)->getModifiedCount();
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function count(string $collection, array $filter = []): int
    {
        $cursor = $this->manager->executeCommand(
            $this->database,
            new \MongoDB\Driver\Command(['count' => $collection, 'query' => $filter]),
        );
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        /** @var list<array<string, mixed>> $result */
        $result = $cursor->toArray();

        return (int) ($result[0]['n'] ?? 0);
    }

    public function deleteAll(string $collection): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->delete([]);

        $this->manager->executeBulkWrite($this->namespaceFor($collection), $bulk);
    }

    /**
     * Індекси розділу 10.5:
     *  - staff_users: unique {email:1}, multikey {storeIds:1};
     *  - refresh_tokens: TTL на expiresAt (expireAfterSeconds:0), {userId:1, revokedAt:1};
     *  - login_attempts: {login:1, at:-1}, TTL на at (30 днів).
     */
    public function ensureIndexes(): void
    {
        $this->manager->executeCommand($this->database, new \MongoDB\Driver\Command([
            'createIndexes' => 'staff_users',
            'indexes' => [
                ['key' => ['email' => 1], 'name' => 'uniq_email', 'unique' => true],
                ['key' => ['storeIds' => 1], 'name' => 'storeIds'],
            ],
        ]));

        $this->manager->executeCommand($this->database, new \MongoDB\Driver\Command([
            'createIndexes' => 'refresh_tokens',
            'indexes' => [
                ['key' => ['expiresAt' => 1], 'name' => 'ttl_expiresAt', 'expireAfterSeconds' => 0],
                ['key' => ['userId' => 1, 'revokedAt' => 1], 'name' => 'user_revoked'],
                ['key' => ['tokenHash' => 1], 'name' => 'uniq_tokenHash', 'unique' => true],
            ],
        ]));

        $this->manager->executeCommand($this->database, new \MongoDB\Driver\Command([
            'createIndexes' => 'login_attempts',
            'indexes' => [
                ['key' => ['login' => 1, 'at' => -1], 'name' => 'login_at'],
                ['key' => ['at' => 1], 'name' => 'ttl_at', 'expireAfterSeconds' => 2_592_000],
            ],
        ]));

        $this->manager->executeCommand($this->database, new \MongoDB\Driver\Command([
            'createIndexes' => 'role_audit',
            'indexes' => [
                ['key' => ['targetUserId' => 1, 'timestamp' => -1], 'name' => 'target_ts'],
                ['key' => ['actorUserId' => 1, 'timestamp' => -1], 'name' => 'actor_ts'],
            ],
        ]));
    }

    public static function toUtcDateTime(\DateTimeImmutable $value): \MongoDB\BSON\UTCDateTime
    {
        return new \MongoDB\BSON\UTCDateTime($value->getTimestamp() * 1000);
    }

    public static function toDateTimeImmutable(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime() instanceof \DateTime
                ? \DateTimeImmutable::createFromMutable($value->toDateTime())->setTimezone(new \DateTimeZone('UTC'))
                : null;
        }

        if (\is_string($value) && '' !== $value) {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return null;
    }
}
