<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Пара токенів, яку повертає логін і ротація (AUTH-11, AUTH-31).
 *
 * Access TTL — 15 хв, refresh TTL — 30 днів (таблиця 3.4, контур staff).
 */
final readonly class IssuedTokens
{
    public function __construct(
        public string $accessToken,
        public \DateTimeImmutable $accessExpiresAt,
        public string $refreshToken,
        public \DateTimeImmutable $refreshExpiresAt,
        public string $sessionId,
        public string $accessJti,
    ) {
    }

    public function accessTtlSeconds(\DateTimeImmutable $now): int
    {
        return max(0, $this->accessExpiresAt->getTimestamp() - $now->getTimestamp());
    }
}
