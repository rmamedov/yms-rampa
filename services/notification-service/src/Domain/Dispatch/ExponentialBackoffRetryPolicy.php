<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Exception\DomainException;

/**
 * Експоненційний backoff (NOT-04).
 *
 * Значення за замовчуванням дають рівно ті інтервали, що зафіксовані
 * в NOT-04: 1, 5, 15 хв — initial=60 c, множник 5, стеля 900 c
 * (60 → 300 → 1500, обрізане стелею до 900).
 *
 * Максимальна кількість спроб конфігурується (NOTIFICATION_MAX_ATTEMPTS).
 */
final readonly class ExponentialBackoffRetryPolicy implements RetryPolicy
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $initialDelaySeconds = 60,
        private float $multiplier = 5.0,
        private int $maxDelaySeconds = 900,
    ) {
        if ($maxAttempts < 1) {
            throw new DomainException(
                'Кількість спроб відправки має бути щонайменше 1.',
                'RETRY_POLICY_INVALID',
                500,
            );
        }
        if ($initialDelaySeconds < 0 || $maxDelaySeconds < $initialDelaySeconds) {
            throw new DomainException(
                'Некоректні інтервали backoff: стеля не може бути меншою за початкову затримку.',
                'RETRY_POLICY_INVALID',
                500,
            );
        }
        if ($multiplier < 1.0) {
            throw new DomainException(
                'Множник backoff має бути не меншим за 1.',
                'RETRY_POLICY_INVALID',
                500,
            );
        }
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function delayForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            $attempt = 1;
        }

        $delay = $this->initialDelaySeconds * $this->multiplier ** ($attempt - 1);

        return (int) min($this->maxDelaySeconds, (int) round($delay));
    }

    public function shouldRetry(int $attemptsMade): bool
    {
        return $attemptsMade < $this->maxAttempts;
    }

    /**
     * Повний розклад затримок — зручно для журналу і для тестів.
     *
     * @return list<int>
     */
    public function schedule(): array
    {
        $schedule = [];
        for ($attempt = 1; $attempt < $this->maxAttempts; ++$attempt) {
            $schedule[] = $this->delayForAttempt($attempt);
        }

        return $schedule;
    }
}
