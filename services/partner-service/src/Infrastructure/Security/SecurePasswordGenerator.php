<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Security\PasswordGenerator;

/**
 * Генератор тимчасових паролів водіїв (SUP-DRV-03, SUP-DRV-04).
 *
 * Вимоги, які закриває клас:
 *  - довжина 12 символів;
 *  - криптостійке джерело випадковості — random_int() (CSPRNG), не rand()/mt_rand();
 *  - без неоднозначних символів `0`, `O`, `1`, `l`, `I`: пароль диктують по SMS
 *    і вводять на телефоні водія, тому сплутати символи не можна;
 *  - гарантована наявність великої літери, малої літери й цифри — щоб пароль
 *    проходив політику складності контуру ідентичності.
 */
final class SecurePasswordGenerator implements PasswordGenerator
{
    public const DEFAULT_LENGTH = 12;

    /** Латиниця у верхньому регістрі без «O» та «I». */
    public const UPPERCASE = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Латиниця в нижньому регістрі без «l». */
    public const LOWERCASE = 'abcdefghijkmnopqrstuvwxyz';

    /** Цифри без «0» та «1». */
    public const DIGITS = '23456789';

    /** Неоднозначні символи, яких у паролі бути не може. */
    public const AMBIGUOUS = ['0', 'O', '1', 'l', 'I'];

    private readonly int $length;

    public function __construct(int $length = self::DEFAULT_LENGTH)
    {
        // Три обов'язкові класи символів + запас: коротший за 4 пароль безглуздий.
        $this->length = max(4, $length);
    }

    public function generate(): string
    {
        $alphabet = self::UPPERCASE.self::LOWERCASE.self::DIGITS;

        // По одному символу з кожного обов'язкового класу…
        $chars = [
            self::pick(self::UPPERCASE),
            self::pick(self::LOWERCASE),
            self::pick(self::DIGITS),
        ];

        // …решту добираємо з повного алфавіту.
        for ($i = \count($chars); $i < $this->length; ++$i) {
            $chars[] = self::pick($alphabet);
        }

        return self::shuffle($chars);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, \strlen($alphabet) - 1)];
    }

    /**
     * Перемішування Фішера–Йетса на random_int(): shuffle() використовує
     * некриптостійкий генератор і зіпсував би стійкість пароля.
     *
     * @param list<string> $chars
     */
    private static function shuffle(array $chars): string
    {
        for ($i = \count($chars) - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
