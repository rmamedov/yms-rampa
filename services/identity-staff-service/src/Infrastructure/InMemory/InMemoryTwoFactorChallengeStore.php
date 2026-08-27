<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Auth\TwoFactorChallengeStore;

/**
 * Одноразові challenge-токени 2FA в памʼяті (AUTH-17, AUTH-62).
 */
final class InMemoryTwoFactorChallengeStore implements TwoFactorChallengeStore
{
    /** @var array<string, array{userId: string, expiresAt: \DateTimeImmutable}> */
    private array $challenges = [];

    public function put(string $tokenHash, string $userId, \DateTimeImmutable $expiresAt): void
    {
        $this->challenges[$tokenHash] = ['userId' => $userId, 'expiresAt' => $expiresAt];
    }

    public function consume(string $tokenHash, \DateTimeImmutable $now): ?string
    {
        $challenge = $this->challenges[$tokenHash] ?? null;

        if (null === $challenge) {
            return null;
        }

        // Одноразовість: запис видаляється незалежно від результату
        unset($this->challenges[$tokenHash]);

        return $challenge['expiresAt'] > $now ? $challenge['userId'] : null;
    }
}
