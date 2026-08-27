<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Session\RefreshToken;
use App\Domain\Session\RefreshTokenRepository;

/**
 * Реалізація `refresh_tokens` у памʼяті (AUTH-30: зберігаються лише хеші —
 * саме такий контракт і в Mongo-реалізації).
 */
final class InMemoryRefreshTokenRepository implements RefreshTokenRepository
{
    /** @var array<string, RefreshToken> ключ — id токена */
    private array $tokens = [];

    public function save(RefreshToken $token): void
    {
        $this->tokens[$token->id] = $token;
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        foreach ($this->tokens as $token) {
            if (hash_equals($token->tokenHash, $tokenHash)) {
                return $token;
            }
        }

        return null;
    }

    public function findBySid(string $sid): array
    {
        return array_values(array_filter(
            $this->tokens,
            static fn (RefreshToken $token): bool => $token->sid === $sid,
        ));
    }

    public function revokeChain(string $sid, \DateTimeImmutable $at): void
    {
        foreach ($this->tokens as $token) {
            if ($token->sid === $sid) {
                $token->revoke($at);
            }
        }
    }

    public function revokeAllForAccount(string $accountId, \DateTimeImmutable $at): void
    {
        foreach ($this->tokens as $token) {
            if ($token->accountId === $accountId) {
                $token->revoke($at);
            }
        }
    }

    public function findActiveForAccount(string $accountId, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->tokens,
            static fn (RefreshToken $token): bool => $token->accountId === $accountId && $token->isUsable($now),
        ));
    }

    /** @return list<RefreshToken> */
    public function all(): array
    {
        return array_values($this->tokens);
    }

    public function clear(): void
    {
        $this->tokens = [];
    }
}
