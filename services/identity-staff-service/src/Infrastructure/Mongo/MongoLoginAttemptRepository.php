<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Auth\LoginAttempt;
use App\Domain\Auth\LoginAttemptRepository;

/**
 * Колекція `login_attempts` (10.6; та сама структура для staff-контуру).
 *
 * DATA-20: індекс {login:1, at:-1} обслуговує лічильник блокувань AUTH-50,
 * TTL на `at` — 30 днів.
 */
final readonly class MongoLoginAttemptRepository implements LoginAttemptRepository
{
    private const string COLLECTION = 'login_attempts';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function record(LoginAttempt $attempt): void
    {
        $this->connection->insert(self::COLLECTION, [
            'login' => $attempt->login,
            'ip' => $attempt->ip,
            'userAgent' => $attempt->userAgent,
            'success' => $attempt->success,
            'at' => MongoConnection::toUtcDateTime($attempt->at),
            'reason' => $attempt->reason,
        ]);
    }

    public function countFailuresSince(string $login, \DateTimeImmutable $since): int
    {
        return $this->connection->count(self::COLLECTION, $this->failureFilter($login, $since));
    }

    public function lastFailureAt(string $login, \DateTimeImmutable $since): ?\DateTimeImmutable
    {
        $documents = $this->connection->find(
            self::COLLECTION,
            $this->failureFilter($login, $since),
            ['sort' => ['at' => -1], 'limit' => 1],
        );

        if ([] === $documents) {
            return null;
        }

        return MongoConnection::toDateTimeImmutable($documents[0]['at'] ?? null);
    }

    public function clearFailures(string $login): void
    {
        // AUTH-50: успішний логін скидає лічильник. Історія спроб зберігається
        // для аудиту (AUTH-52), тому запис не видаляється, а виводиться з-під
        // лічильника прапорцем `counted`.
        $this->connection->updateMany(
            self::COLLECTION,
            ['login' => $login, 'success' => false, 'counted' => ['$ne' => false]],
            ['counted' => false],
        );
    }

    public function recentFor(string $login, \DateTimeImmutable $since): array
    {
        $documents = $this->connection->find(
            self::COLLECTION,
            ['login' => $login, 'at' => ['$gte' => MongoConnection::toUtcDateTime($since)]],
            ['sort' => ['at' => -1]],
        );

        return array_map(
            static fn (array $document): LoginAttempt => new LoginAttempt(
                login: (string) $document['login'],
                success: (bool) $document['success'],
                at: MongoConnection::toDateTimeImmutable($document['at'] ?? null) ?? new \DateTimeImmutable('@0'),
                ip: isset($document['ip']) ? (string) $document['ip'] : null,
                userAgent: isset($document['userAgent']) ? (string) $document['userAgent'] : null,
                reason: isset($document['reason']) ? (string) $document['reason'] : null,
            ),
            $documents,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function failureFilter(string $login, \DateTimeImmutable $since): array
    {
        return [
            'login' => $login,
            'success' => false,
            'counted' => ['$ne' => false],
            'at' => ['$gte' => MongoConnection::toUtcDateTime($since)],
        ];
    }
}
