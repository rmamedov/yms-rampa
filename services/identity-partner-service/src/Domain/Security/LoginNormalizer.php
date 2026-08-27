<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Account\ClientType;
use App\Domain\Account\PartnerRole;
use App\Domain\Exception\InvalidLoginFormatException;

/**
 * Нормалізація логіна партнерського контуру перед пошуком і збереженням.
 *
 * AUTH-23: логін водія — телефон E.164; AUTH-21 + 10.6: логін постачальника —
 * email у нижньому регістрі з обрізаними пробілами.
 */
final readonly class LoginNormalizer
{
    public function __construct(private PhoneNormalizer $phones)
    {
    }

    /** Нормалізація за роллю акаунта (створення акаунта). */
    public function normalizeForRole(PartnerRole $role, string $raw): string
    {
        return $role->loginIsPhone()
            ? $this->phones->normalize($raw)
            : $this->normalizeEmail($raw);
    }

    /** Нормалізація за застосунком, з якого прийшов логін (флоу входу). */
    public function normalizeForClient(ClientType $client, string $raw): string
    {
        return $client->loginIsPhone()
            ? $this->phones->normalize($raw)
            : $this->normalizeEmail($raw);
    }

    /**
     * М'який варіант для флоу логіну: непридатний ввід не має відрізнятись від
     * «просто невірного» логіна (AUTH-53).
     */
    public function tryNormalizeForClient(ClientType $client, string $raw): ?string
    {
        if ($client->loginIsPhone()) {
            return $this->phones->tryNormalize($raw);
        }

        $email = strtolower(trim($raw));

        return $this->isEmail($email) ? $email : null;
    }

    public function normalizeEmail(string $raw): string
    {
        $email = strtolower(trim($raw));

        if (!$this->isEmail($email)) {
            throw InvalidLoginFormatException::email($raw);
        }

        return $email;
    }

    /**
     * Маскування логіна для аудит-журналу (AUTH-52: логін масковано).
     */
    public function mask(string $login): string
    {
        if (str_contains($login, '@')) {
            [$name, $domain] = explode('@', $login, 2);

            return mb_substr($name, 0, 2).str_repeat('*', max(1, mb_strlen($name) - 2)).'@'.$domain;
        }

        $length = mb_strlen($login);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($login, 0, 4).str_repeat('*', $length - 8).mb_substr($login, -4);
    }

    private function isEmail(string $value): bool
    {
        return false !== filter_var($value, \FILTER_VALIDATE_EMAIL);
    }
}
