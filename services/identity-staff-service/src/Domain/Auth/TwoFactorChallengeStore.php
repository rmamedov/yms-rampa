<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Сховище короткоживучих challenge-токенів 2FA (AUTH-17, AUTH-62).
 *
 * Токени зберігаються ЛИШЕ хешованими (SHA-256), одноразові, TTL 5 хвилин.
 */
interface TwoFactorChallengeStore
{
    public function put(string $tokenHash, string $userId, \DateTimeImmutable $expiresAt): void;

    /**
     * Одноразове використання: успішне читання видаляє запис.
     *
     * @return string|null id користувача або null, якщо токен невідомий/прострочений
     */
    public function consume(string $tokenHash, \DateTimeImmutable $now): ?string;
}
