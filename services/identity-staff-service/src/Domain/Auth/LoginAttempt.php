<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Запис колекції `login_attempts` (10.6, застосовується і в staff-контурі).
 *
 * AUTH-52: кожна невдала і успішна спроба логіну фіксується в аудит-журналі
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

    /**
     * AUTH-52/AUTH-61: логін у журналі маскується — «iv****@silpo.ua».
     */
    public function maskedLogin(): string
    {
        $at = strrpos($this->login, '@');

        if (false === $at || $at < 1) {
            return str_repeat('*', mb_strlen($this->login));
        }

        $local = substr($this->login, 0, $at);
        $domain = substr($this->login, $at);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))).$domain;
    }
}
