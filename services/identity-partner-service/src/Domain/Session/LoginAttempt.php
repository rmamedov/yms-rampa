<?php

declare(strict_types=1);

namespace App\Domain\Session;

/**
 * Запис колекції `login_attempts` (10.6, DATA-20).
 *
 * AUTH-52: кожна невдала й успішна спроба логіну фіксується в аудит-журналі
 * (логін масковано, IP, userAgent, час UTC, результат).
 */
final readonly class LoginAttempt
{
    public function __construct(
        public string $login,
        public bool $success,
        public \DateTimeImmutable $at,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $reason = null,
    ) {
    }

    public static function failure(string $login, \DateTimeImmutable $at, ?string $ip, ?string $userAgent, string $reason): self
    {
        return new self($login, false, $at, $ip, $userAgent, $reason);
    }

    public static function success(string $login, \DateTimeImmutable $at, ?string $ip, ?string $userAgent): self
    {
        return new self($login, true, $at, $ip, $userAgent, null);
    }
}
