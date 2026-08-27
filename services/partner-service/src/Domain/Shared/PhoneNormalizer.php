<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Нормалізація українських телефонних номерів до канонічного E.164-вигляду
 * `+380XXXXXXXXX` (SUP-DRV-02, STC-02).
 *
 * Телефон водія одночасно є його логіном у identity-partner-service
 * (DATA-17, DATA-35), тому будь-який варіант вводу мусить давати рівно один
 * канонічний рядок — інакше правило глобальної унікальності телефону
 * можна обійти простим переформатуванням.
 *
 * Приймаються формати: `+380671112233`, `380671112233`, `00380671112233`,
 * `0671112233`, `671112233`, а також будь-які з них із пробілами, дефісами,
 * крапками та дужками.
 */
final class PhoneNormalizer
{
    /**
     * Канонічний вигляд: `+380` + 9 цифр, де перша цифра — код оператора/регіону
     * (в Україні це 3–9; кодів 01xx і 02xx не існує).
     */
    private const CANONICAL_PATTERN = '/^\+380[3-9]\d{8}$/';

    /**
     * @throws ValidationException якщо номер не зводиться до +380XXXXXXXXX
     */
    public static function normalize(string $raw): string
    {
        $trimmed = trim($raw);

        if ('' === $trimmed) {
            throw new ValidationException('Вкажіть номер телефону.', 'PHONE_REQUIRED');
        }

        // Прибираємо все, крім цифр і провідного «+»: пробіли, дефіси, дужки, крапки.
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        // Міжнародний префікс 00 еквівалентний «+».
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $national = match (true) {
            // 380671112233 / +380671112233
            12 === \strlen($digits) && str_starts_with($digits, '380') => substr($digits, 3),
            // 0671112233 — національний формат
            10 === \strlen($digits) && str_starts_with($digits, '0') => substr($digits, 1),
            // 671112233 — абонентський номер без коду країни і без нуля
            9 === \strlen($digits) && !str_starts_with($digits, '0') => $digits,
            default => null,
        };

        if (null === $national) {
            throw new ValidationException(
                \sprintf('Невірний формат телефону «%s». Очікується +380XXXXXXXXX.', $trimmed),
                'PHONE_INVALID',
            );
        }

        $normalized = '+380'.$national;

        if (1 !== preg_match(self::CANONICAL_PATTERN, $normalized)) {
            throw new ValidationException(
                \sprintf('Невірний формат телефону «%s». Очікується +380XXXXXXXXX.', $trimmed),
                'PHONE_INVALID',
            );
        }

        return $normalized;
    }

    /**
     * Нормалізує необов'язковий телефон: порожній рядок і null дають null.
     */
    public static function normalizeOptional(?string $raw): ?string
    {
        if (null === $raw || '' === trim($raw)) {
            return null;
        }

        return self::normalize($raw);
    }

    public static function isValid(string $raw): bool
    {
        try {
            self::normalize($raw);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
