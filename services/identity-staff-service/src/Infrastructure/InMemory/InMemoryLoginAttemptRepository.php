<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Auth\LoginAttempt;
use App\Domain\Auth\LoginAttemptRepository;

/**
 * Журнал спроб логіну в памʼяті (AUTH-50, AUTH-52, DATA-20).
 */
final class InMemoryLoginAttemptRepository implements LoginAttemptRepository
{
    /** @var list<LoginAttempt> */
    private array $attempts = [];

    public function record(LoginAttempt $attempt): void
    {
        $this->attempts[] = $attempt;
    }

    public function countFailuresSince(string $login, \DateTimeImmutable $since): int
    {
        return \count($this->failuresSince($login, $since));
    }

    public function lastFailureAt(string $login, \DateTimeImmutable $since): ?\DateTimeImmutable
    {
        $last = null;

        foreach ($this->failuresSince($login, $since) as $attempt) {
            if (null === $last || $attempt->at > $last) {
                $last = $attempt->at;
            }
        }

        return $last;
    }

    public function clearFailures(string $login): void
    {
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (LoginAttempt $attempt): bool => $attempt->login !== $login || $attempt->success,
        ));
    }

    public function recentFor(string $login, \DateTimeImmutable $since): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (LoginAttempt $attempt): bool => $attempt->login === $login && $attempt->at >= $since,
        ));
    }

    /**
     * @return list<LoginAttempt>
     */
    public function all(): array
    {
        return $this->attempts;
    }

    /**
     * @return list<LoginAttempt>
     */
    private function failuresSince(string $login, \DateTimeImmutable $since): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (LoginAttempt $attempt): bool => $attempt->login === $login
                && !$attempt->success
                && $attempt->at >= $since,
        ));
    }
}
