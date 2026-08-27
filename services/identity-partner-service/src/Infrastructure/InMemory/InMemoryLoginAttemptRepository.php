<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Session\LoginAttempt;
use App\Domain\Session\LoginAttemptRepository;

/**
 * Реалізація `login_attempts` у памʼяті (DATA-20, AUTH-50).
 *
 * У проді той самий контракт обслуговують Redis-лічильник (швидка перевірка)
 * і Mongo-колекція (аудит-журнал з TTL 30 днів).
 */
final class InMemoryLoginAttemptRepository implements LoginAttemptRepository
{
    /** @var list<LoginAttempt> */
    private array $attempts = [];

    public function add(LoginAttempt $attempt): void
    {
        $this->attempts[] = $attempt;
    }

    public function findFailedSince(string $login, \DateTimeImmutable $since): array
    {
        $failed = array_filter(
            $this->attempts,
            static fn (LoginAttempt $attempt): bool => $attempt->login === $login
                && !$attempt->success
                && $attempt->at >= $since,
        );

        usort($failed, static fn (LoginAttempt $a, LoginAttempt $b): int => $a->at <=> $b->at);

        return array_values($failed);
    }

    public function clearFailuresFor(string $login): void
    {
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (LoginAttempt $attempt): bool => !($attempt->login === $login && !$attempt->success),
        ));
    }

    /** @return list<LoginAttempt> */
    public function all(): array
    {
        return $this->attempts;
    }

    public function clear(): void
    {
        $this->attempts = [];
    }
}
