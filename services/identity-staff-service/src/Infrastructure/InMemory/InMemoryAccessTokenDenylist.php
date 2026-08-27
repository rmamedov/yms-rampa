<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Auth\AccessTokenDenylist;
use App\Domain\Shared\Clock;

/**
 * Denylist access-токенів у памʼяті (AUTH-17, AUTH-28).
 *
 * Прод-реалізація — Redis із TTL = залишок життя токена.
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
        $this->revoked[$jti] = $expiresAt;
    }

    public function isRevoked(string $jti): bool
    {
        $expiresAt = $this->revoked[$jti] ?? null;

        if (null === $expiresAt) {
            return false;
        }

        // Емуляція TTL: запис живе рівно до `exp` токена
        if ($expiresAt <= $this->clock->now()) {
            unset($this->revoked[$jti]);

            return false;
        }

        return true;
    }
}
