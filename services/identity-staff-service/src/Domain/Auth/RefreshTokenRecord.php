<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Запис колекції `refresh_tokens` (10.5).
 *
 * AUTH-30 / DATA-19: сам токен НЕ зберігається — лише SHA-256-хеш.
 * Поля: sid, userId, expiresAt, createdAt, userAgent, ip, revokedAt.
 */
final readonly class RefreshTokenRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $sessionId,
        public string $tokenHash,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $revokedAt = null,
        public ?string $userAgent = null,
        public ?string $ip = null,
    ) {
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * AUTH-31: використаний refresh позначається погашеним.
     */
    public function revoked(\DateTimeImmutable $at): self
    {
        return new self(
            id: $this->id,
            userId: $this->userId,
            sessionId: $this->sessionId,
            tokenHash: $this->tokenHash,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            revokedAt: $this->revokedAt ?? $at,
            userAgent: $this->userAgent,
            ip: $this->ip,
        );
    }

    /**
     * AUTH-30: refresh-токени зберігаються лише у вигляді SHA-256-хеша.
     */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
