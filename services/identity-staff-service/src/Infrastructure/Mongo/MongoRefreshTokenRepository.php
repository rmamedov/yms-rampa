<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Auth\RefreshTokenRecord;
use App\Domain\Auth\RefreshTokenRepository;

/**
 * Колекція `refresh_tokens` БД `identity_staff` (10.5).
 *
 * AUTH-30: зберігається лише SHA-256-хеш токена.
 * TTL-індекс на `expiresAt` (expireAfterSeconds:0) прибирає прострочені записи.
 */
final readonly class MongoRefreshTokenRepository implements RefreshTokenRepository
{
    private const string COLLECTION = 'refresh_tokens';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function save(RefreshTokenRecord $record): void
    {
        $this->connection->upsert(self::COLLECTION, ['_id' => $record->id], [
            '_id' => $record->id,
            'userId' => $record->userId,
            'sid' => $record->sessionId,
            'tokenHash' => $record->tokenHash,
            'issuedAt' => MongoConnection::toUtcDateTime($record->issuedAt),
            'expiresAt' => MongoConnection::toUtcDateTime($record->expiresAt),
            'revokedAt' => null === $record->revokedAt
                ? null
                : MongoConnection::toUtcDateTime($record->revokedAt),
            'userAgent' => $record->userAgent,
            'ip' => $record->ip,
            'schemaVersion' => 1,
        ]);
    }

    public function findByHash(string $tokenHash): ?RefreshTokenRecord
    {
        $document = $this->connection->findOne(self::COLLECTION, ['tokenHash' => $tokenHash]);

        return null === $document ? null : self::hydrate($document);
    }

    public function revokeSession(string $sessionId, \DateTimeImmutable $now): int
    {
        return $this->connection->updateMany(
            self::COLLECTION,
            ['sid' => $sessionId, 'revokedAt' => null],
            ['revokedAt' => MongoConnection::toUtcDateTime($now)],
        );
    }

    public function revokeAllForUser(string $userId, \DateTimeImmutable $now, ?string $exceptSessionId = null): int
    {
        $filter = ['userId' => $userId, 'revokedAt' => null];

        if (null !== $exceptSessionId) {
            $filter['sid'] = ['$ne' => $exceptSessionId];
        }

        return $this->connection->updateMany(
            self::COLLECTION,
            $filter,
            ['revokedAt' => MongoConnection::toUtcDateTime($now)],
        );
    }

    public function findActiveForUser(string $userId, \DateTimeImmutable $now): array
    {
        $documents = $this->connection->find(self::COLLECTION, [
            'userId' => $userId,
            'revokedAt' => null,
            'expiresAt' => ['$gt' => MongoConnection::toUtcDateTime($now)],
        ]);

        return array_map(self::hydrate(...), $documents);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function hydrate(array $document): RefreshTokenRecord
    {
        return new RefreshTokenRecord(
            id: (string) $document['_id'],
            userId: (string) $document['userId'],
            sessionId: (string) $document['sid'],
            tokenHash: (string) $document['tokenHash'],
            issuedAt: MongoConnection::toDateTimeImmutable($document['issuedAt'] ?? null) ?? new \DateTimeImmutable('@0'),
            expiresAt: MongoConnection::toDateTimeImmutable($document['expiresAt'] ?? null) ?? new \DateTimeImmutable('@0'),
            revokedAt: MongoConnection::toDateTimeImmutable($document['revokedAt'] ?? null),
            userAgent: isset($document['userAgent']) ? (string) $document['userAgent'] : null,
            ip: isset($document['ip']) ? (string) $document['ip'] : null,
        );
    }
}
