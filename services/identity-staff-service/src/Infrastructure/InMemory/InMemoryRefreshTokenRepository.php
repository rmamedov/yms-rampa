<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Auth\RefreshTokenRecord;
use App\Domain\Auth\RefreshTokenRepository;

/**
 * Сховище refresh-токенів у памʼяті (AUTH-30, AUTH-31, AUTH-32).
 */
final class InMemoryRefreshTokenRepository implements RefreshTokenRepository
{
    /** @var array<string, RefreshTokenRecord> */
    private array $byId = [];

    public function save(RefreshTokenRecord $record): void
    {
        $this->byId[$record->id] = $record;
    }

    public function findByHash(string $tokenHash): ?RefreshTokenRecord
    {
        foreach ($this->byId as $record) {
            if (hash_equals($record->tokenHash, $tokenHash)) {
                return $record;
            }
        }

        return null;
    }

    public function revokeSession(string $sessionId, \DateTimeImmutable $now): int
    {
        $revoked = 0;

        foreach ($this->byId as $id => $record) {
            if ($record->sessionId === $sessionId && !$record->isRevoked()) {
                $this->byId[$id] = $record->revoked($now);
                ++$revoked;
            }
        }

        return $revoked;
    }

    public function revokeAllForUser(string $userId, \DateTimeImmutable $now, ?string $exceptSessionId = null): int
    {
        $revoked = 0;

        foreach ($this->byId as $id => $record) {
            if ($record->userId !== $userId || $record->isRevoked()) {
                continue;
            }

            if (null !== $exceptSessionId && $record->sessionId === $exceptSessionId) {
                continue;
            }

            $this->byId[$id] = $record->revoked($now);
            ++$revoked;
        }

        return $revoked;
    }

    public function findActiveForUser(string $userId, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (RefreshTokenRecord $record): bool => $record->userId === $userId
                && !$record->isRevoked()
                && !$record->isExpiredAt($now),
        ));
    }

    /**
     * @return list<RefreshTokenRecord>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
