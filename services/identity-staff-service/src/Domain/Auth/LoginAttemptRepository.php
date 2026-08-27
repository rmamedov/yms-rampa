<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Журнал спроб логіну — основа для лічильника блокувань (AUTH-50, DATA-20).
 *
 * У проді лічильник живе в Redis з TTL 15 хв, історія — у Mongo
 * з індексом `{login:1, at:-1}` і TTL 30 днів.
 */
interface LoginAttemptRepository
{
    public function record(LoginAttempt $attempt): void;

    /**
     * Кількість невдалих спроб для логіна від моменту $since.
     */
    public function countFailuresSince(string $login, \DateTimeImmutable $since): int;

    /**
     * Час останньої невдалої спроби від моменту $since (для обчислення кінця блокування).
     */
    public function lastFailureAt(string $login, \DateTimeImmutable $since): ?\DateTimeImmutable;

    /**
     * AUTH-50: успішний логін скидає лічильник.
     */
    public function clearFailures(string $login): void;

    /**
     * @return list<LoginAttempt>
     */
    public function recentFor(string $login, \DateTimeImmutable $since): array;
}
