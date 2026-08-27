<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\AccountProfile;

/**
 * Результат логіну/refresh: пара токенів + профіль (AUTH-11, AUTH-31).
 *
 * Значення refreshToken — єдине місце, де непрозорий токен існує у відкритому
 * вигляді; у БД лежить лише його SHA-256-хеш (AUTH-30).
 */
final readonly class AuthResult
{
    public function __construct(
        public string $accessToken,
        public \DateTimeImmutable $accessExpiresAt,
        public int $accessExpiresIn,
        public string $refreshToken,
        public \DateTimeImmutable $refreshExpiresAt,
        public string $sid,
        public AccountProfile $profile,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'accessExpiresAt' => $this->accessExpiresAt->format(\DATE_ATOM),
            'expiresIn' => $this->accessExpiresIn,
            'refreshToken' => $this->refreshToken,
            'refreshExpiresAt' => $this->refreshExpiresAt->format(\DATE_ATOM),
            'tokenType' => 'Bearer',
            'profile' => $this->profile->toArray(),
        ];
    }
}
