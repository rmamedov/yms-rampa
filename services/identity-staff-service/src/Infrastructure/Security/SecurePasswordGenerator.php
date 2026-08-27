<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Password\PasswordGenerator;
use App\Domain\Password\PasswordPolicy;

/**
 * Криптостійкий генератор тимчасових паролів staff-контуру.
 *
 * Вимоги, які закриває клас:
 *  - джерело випадковості — random_int() (CSPRNG), не rand()/mt_rand();
 *  - довжина не менша за мінімум політики AUTH-13 (12 символів), із запасом;
 *  - без неоднозначних символів `0`, `O`, `1`, `l`, `I`: пароль диктують
 *    голосом і переписують з екрана — сплутати символи не можна;
 *  - гарантована наявність великої літери, малої і цифри.
 *
 * Правила складності НЕ дублюються: результат проганяється через наявну
 * PasswordPolicy, і якщо випадковий рядок таки порушив якесь правило
 * (наприклад, чотири однакові символи поспіль), генерація повторюється.
 * Так генератор лишається узгодженим із політикою автоматично — навіть
 * якщо політику посилять.
 */
final readonly class SecurePasswordGenerator implements PasswordGenerator
{
    /** Мінімум політики (12) + запас на ентропію. */
    public const int DEFAULT_LENGTH = 16;

    /** Латиниця у верхньому регістрі без «O» та «I». */
    public const string UPPERCASE = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Латиниця в нижньому регістрі без «l». */
    public const string LOWERCASE = 'abcdefghijkmnopqrstuvwxyz';

    /** Цифри без «0» та «1». */
    public const string DIGITS = '23456789';

    /** Скільки разів повторити спробу, перш ніж визнати конфігурацію непридатною. */
    private const int MAX_ATTEMPTS = 20;

    private int $length;

    public function __construct(
        private PasswordPolicy $passwordPolicy,
        int $length = self::DEFAULT_LENGTH,
    ) {
        // Коротший за мінімум політики пароль не пройшов би валідацію
        // жодного разу — тому довжина не може бути меншою.
        $this->length = max($passwordPolicy->minLength(), $length);
    }

    public function generate(?string $email = null, ?string $fullName = null): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $candidate = $this->candidate();

            if ([] === $this->passwordPolicy->validate($candidate, $email, $fullName)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Не вдалося згенерувати пароль, який відповідає політиці AUTH-13.',
        );
    }

    private function candidate(): string
    {
        $alphabet = self::UPPERCASE.self::LOWERCASE.self::DIGITS;

        // По одному символу з кожного обовʼязкового класу…
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
