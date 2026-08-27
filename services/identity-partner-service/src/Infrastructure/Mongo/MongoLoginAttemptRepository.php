<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Session\LoginAttempt;
use App\Domain\Session\LoginAttemptRepository;

/**
 * Колекція `identity_partner.login_attempts` (10.6, DATA-20).
 *
 * Індекси: `{login:1, at:-1}` — обчислення блокування (AUTH-50);
 * TTL на `at` — 30 днів (аудит-журнал, AUTH-52).
 */
final class MongoLoginAttemptRepository extends MongoSupport implements LoginAttemptRepository
{
    public function add(LoginAttempt $attempt): void
    {
        $this->insert([
            'login' => $attempt->login,
            'ip' => $attempt->ip,
            'userAgent' => $attempt->userAgent,
            'success' => $attempt->success,
            'at' => self::toBson($attempt->at),
            'reason' => $attempt->reason,
        ]);
    }

    public function findFailedSince(string $login, \DateTimeImmutable $since): array
    {
        return array_map(
            static fn (array $document): LoginAttempt => self::hydrate($document),
            $this->find(
                ['login' => $login, 'success' => false, 'at' => ['$gte' => self::toBson($since)]],
                ['sort' => ['at' => 1]],
            ),
        );
    }

    public function clearFailuresFor(string $login): void
    {
        $this->deleteMany(['login' => $login, 'success' => false]);
    }

    protected function collection(): string
    {
        return 'login_attempts';
    }

    /** @param array<string, mixed> $document */
    private static function hydrate(array $document): LoginAttempt
    {
        return new LoginAttempt(
            login: (string) $document['login'],
            success: (bool) ($document['success'] ?? false),
            at: self::fromBson($document['at'] ?? null) ?? new \DateTimeImmutable('@0'),
            ip: isset($document['ip']) && \is_string($document['ip']) ? $document['ip'] : null,
            userAgent: isset($document['userAgent']) && \is_string($document['userAgent']) ? $document['userAgent'] : null,
            reason: isset($document['reason']) && \is_string($document['reason']) ? $document['reason'] : null,
        );
    }
}
