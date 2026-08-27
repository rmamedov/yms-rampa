<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exception\AccountLockedException;
use App\Domain\Shared\Clock;

/**
 * Захист від перебору паролів (AUTH-50, DATA-20).
 *
 * Після 5 невдалих спроб логіну поспіль для одного логіна обліковий запис
 * блокується на 15 хвилин. Лічильник має TTL 15 хв і скидається успішним логіном.
 * Блокування повертає AUTH_ACCOUNT_LOCKED незалежно від правильності пароля.
 *
 * Крайовий випадок: для НЕІСНУЮЧОГО логіна поведінка і тексти ідентичні —
 * лічильник ведеться за рядком логіна, а не за знайденим користувачем,
 * тому блокування не розкриває існування облікового запису.
 */
final readonly class LoginThrottler
{
    public const int MAX_FAILURES = 5;
    public const int WINDOW_SECONDS = 900;  // 15 хвилин
    public const int LOCK_SECONDS = 900;    // 15 хвилин

    public function __construct(
        private LoginAttemptRepository $attempts,
        private Clock $clock,
        private int $maxFailures = self::MAX_FAILURES,
        private int $windowSeconds = self::WINDOW_SECONDS,
        private int $lockSeconds = self::LOCK_SECONDS,
    ) {
    }

    /**
     * @throws AccountLockedException 423, якщо акаунт у періоді блокування
     */
    public function assertNotLocked(string $login): void
    {
        $lockedUntil = $this->lockedUntil($login);

        if (null !== $lockedUntil) {
            throw new AccountLockedException($lockedUntil);
        }
    }

    public function isLocked(string $login): bool
    {
        return null !== $this->lockedUntil($login);
    }

    /**
     * @return \DateTimeImmutable|null час завершення блокування або null, якщо блокування немає
     */
    public function lockedUntil(string $login): ?\DateTimeImmutable
    {
        $login = self::normalize($login);
        $now = $this->clock->now();
        $since = $now->modify(\sprintf('-%d seconds', $this->windowSeconds));

        if ($this->attempts->countFailuresSince($login, $since) < $this->maxFailures) {
            return null;
        }

        $lastFailureAt = $this->attempts->lastFailureAt($login, $since);

        if (null === $lastFailureAt) {
            return null;
        }

        $lockedUntil = $lastFailureAt->modify(\sprintf('+%d seconds', $this->lockSeconds));

        return $lockedUntil > $now ? $lockedUntil : null;
    }

    /**
     * Скільки спроб лишилось до блокування — для логів і моніторингу
     * (користувачу це число не показується, AUTH-53).
     */
    public function remainingAttempts(string $login): int
    {
        $now = $this->clock->now();
        $since = $now->modify(\sprintf('-%d seconds', $this->windowSeconds));
        $failures = $this->attempts->countFailuresSince(self::normalize($login), $since);

        return max(0, $this->maxFailures - $failures);
    }

    public function registerFailure(
        string $login,
        ?string $ip = null,
        ?string $userAgent = null,
        string $reason = 'invalid_credentials',
    ): void {
        $this->attempts->record(new LoginAttempt(
            login: self::normalize($login),
            success: false,
            at: $this->clock->now(),
            ip: $ip,
            userAgent: $userAgent,
            reason: $reason,
        ));
    }

    /**
     * AUTH-50: успішний логін скидає лічильник невдалих спроб.
     */
    public function registerSuccess(string $login, ?string $ip = null, ?string $userAgent = null): void
    {
        $normalized = self::normalize($login);

        $this->attempts->record(new LoginAttempt(
            login: $normalized,
            success: true,
            at: $this->clock->now(),
            ip: $ip,
            userAgent: $userAgent,
        ));

        $this->attempts->clearFailures($normalized);
    }

    private static function normalize(string $login): string
    {
        return mb_strtolower(trim($login));
    }
}
