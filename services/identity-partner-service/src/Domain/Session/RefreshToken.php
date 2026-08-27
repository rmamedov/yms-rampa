<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Account\UserType;

/**
 * Refresh-токен сесії — колекція `refresh_tokens` (10.6).
 *
 * AUTH-30: у БД лежить лише SHA-256-хеш; сам токен не зберігається ніде.
 * AUTH-31: кожне використання токена «гасить» його (redeemedAt) і видає новий
 * у межах того самого ланцюжка `sid`.
 */
final class RefreshToken
{
    private ?\DateTimeImmutable $revokedAt = null;

    private ?\DateTimeImmutable $redeemedAt = null;

    public function __construct(
        public readonly string $id,
        public readonly string $sid,
        public readonly string $accountId,
        public readonly string $tokenHash,
        public readonly UserType $userType,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly \DateTimeImmutable $expiresAt,
        /** Тривалість сесії, щоб ротація зберігала «довгу сесію водія» (DRV-07). */
        public readonly int $ttlSeconds,
        public readonly ?string $userAgent = null,
        public readonly ?string $ip = null,
        ?\DateTimeImmutable $revokedAt = null,
        ?\DateTimeImmutable $redeemedAt = null,
    ) {
        $this->revokedAt = $revokedAt;
        $this->redeemedAt = $redeemedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function redeemedAt(): ?\DateTimeImmutable
    {
        return $this->redeemedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /** Токен уже використано для ротації — повторне використання = крадіжка (AUTH-31). */
    public function isRedeemed(): bool
    {
        return null !== $this->redeemedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isRedeemed() && !$this->isExpired($now);
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    public function redeem(\DateTimeImmutable $at): void
    {
        $this->redeemedAt ??= $at;
    }
}
