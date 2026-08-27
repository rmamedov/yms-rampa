<?php

declare(strict_types=1);

namespace App\Domain\Session;

/**
 * Журнал спроб логіну — основа лічильника блокувань (DATA-20, AUTH-50).
 *
 * У проді лічильник дублюється в Redis (швидка перевірка), а колекція
 * `login_attempts` лишається аудит-журналом із TTL 30 днів.
 */
interface LoginAttemptRepository
{
    public function add(LoginAttempt $attempt): void;

    /**
     * Невдалі спроби для логіна від моменту $since (включно), у хронологічному
     * порядку.
     *
     * @return list<LoginAttempt>
     */
    public function findFailedSince(string $login, \DateTimeImmutable $since): array;

    /** Скидання лічильника після успішного логіну (AUTH-50). */
    public function clearFailuresFor(string $login): void;
}
