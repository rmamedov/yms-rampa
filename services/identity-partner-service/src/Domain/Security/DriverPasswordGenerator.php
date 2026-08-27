<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Генератор пароля водія (AUTH-24).
 *
 * 10 символів з алфавіту без омоглифів (виключені 0, O, 1, l, I),
 * криптографічно стійкий генератор. Пароль показується постачальнику рівно
 * один раз і надсилається водієві в SMS за подією DriverCreated.
 */
final readonly class DriverPasswordGenerator
{
    /** Алфавіт без омоглифів: немає 0, O, 1, l, I. */
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public const int LENGTH = 10;

    public function generate(int $length = self::LENGTH): string
    {
        if ($length < 8) {
            throw new \InvalidArgumentException('Довжина згенерованого пароля не може бути меншою за 8 символів.');
        }

        $alphabetLength = \strlen(self::ALPHABET);
        $password = '';

        for ($i = 0; $i < $length; ++$i) {
            $password .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $password;
    }
}
