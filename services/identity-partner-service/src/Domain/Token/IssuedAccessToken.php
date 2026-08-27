<?php

declare(strict_types=1);

namespace App\Domain\Token;

/** Щойно випущений access-токен разом із метаданими (розділ 3.4). */
final readonly class IssuedAccessToken
{
    public function __construct(
        public string $token,
        public string $jti,
        public string $sid,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /** Скільки секунд лишилось жити токену (для поля expiresIn у відповіді). */
    public function expiresInSeconds(): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $this->issuedAt->getTimestamp());
    }
}
