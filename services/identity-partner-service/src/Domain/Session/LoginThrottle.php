<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Clock\Clock;
use App\Domain\Exception\AccountLockedException;

/**
 * Блокування після серії невдалих спроб (AUTH-50, DATA-20, DRV-11).
 *
 * 5 невдалих спроб поспіль для одного логіна → блокування на 15 хвилин.
 * Перевірка виконується ДО пошуку акаунта, тому поведінка для неіснуючого
 * логіна ідентична (крайовий випадок 3.6).
 */
final readonly class LoginThrottle
{
    public function __construct(
        private LoginAttemptRepository $attempts,
        private Clock $clock,
        private int $maxFailures = 5,
        private int $windowSeconds = 900,
    ) {
    }

    /**
     * @throws AccountLockedException якщо логін заблоковано
     */
    public function assertNotLocked(string $login): void
    {
        $now = $this->clock->now();
        $failures = $this->attempts->findFailedSince($login, $now->modify(\sprintf('-%d seconds', $this->windowSeconds)));

        if (\count($failures) < $this->maxFailures) {
            return;
        }

        // TTL лічильника відлічується від першої невдалої спроби у вікні —
        // так само, як TTL ключа в Redis (AUTH-50).
        $firstFailureAt = $failures[0]->at;
        $unlockAt = $firstFailureAt->modify(\sprintf('+%d seconds', $this->windowSeconds));
        $retryAfter = max(1, $unlockAt->getTimestamp() - $now->getTimestamp());

        throw new AccountLockedException($retryAfter);
    }

    public function registerFailure(string $login, string $reason, ?string $ip = null, ?string $userAgent = null): void
    {
        $this->attempts->add(LoginAttempt::failure($login, $this->clock->now(), $ip, $userAgent, $reason));
    }

    /** Успішний логін скидає лічильник (AUTH-50). */
    public function registerSuccess(string $login, ?string $ip = null, ?string $userAgent = null): void
    {
        $this->attempts->clearFailuresFor($login);
        $this->attempts->add(LoginAttempt::success($login, $this->clock->now(), $ip, $userAgent));
    }

    public function maxFailures(): int
    {
        return $this->maxFailures;
    }
}
