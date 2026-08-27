<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Auth\AccessTokenDenylist;
use App\Domain\Clock\Clock;

/**
 * Denylist access-токенів у памʼяті (AUTH-28, AUTH-32).
 *
 * Використовується юніт-тестами і dev-режимом без Redis. Прод-реалізація —
 * App\Infrastructure\Redis\RedisAccessTokenDenylist.
 */
final class InMemoryAccessTokenDenylist implements AccessTokenDenylist
{
    /** @var array<string, \DateTimeImmutable> */
    private array $revoked = [];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function revoke(string $jti, \DateTimeImmutable $expiresAt): void
    {
        if ('' === $jti) {
            return;
        }

        $this->revoked[$jti] = $expiresAt;
    }

    public function isRevoked(string $jti): bool
    {
        $expiresAt = $this->revoked[$jti] ?? null;

        if (null === $expiresAt) {
            return false;
        }

        // Емуляція TTL: запис живе рівно до `exp` токена.
        if ($expiresAt <= $this->clock->now()) {
            unset($this->revoked[$jti]);

            return false;
        }

        return true;
    }

    public function clear(): void
    {
        $this->revoked = [];
    }
}
