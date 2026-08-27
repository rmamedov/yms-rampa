<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

/**
 * Політика повторних спроб відправки (NOT-04).
 */
interface RetryPolicy
{
    /** Максимальна кількість спроб; конфігурується. */
    public function maxAttempts(): int;

    /**
     * Затримка перед спробою №$attempt (1 — це пауза після першої невдачі).
     *
     * @return int секунди
     */
    public function delayForAttempt(int $attempt): int;

    /** Чи є сенс у ще одній спробі після $attemptsMade невдач. */
    public function shouldRetry(int $attemptsMade): bool;
}
