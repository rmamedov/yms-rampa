<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Exception\InvalidLoginFormatException;

/**
 * Нормалізація українського номера телефону до E.164 `+380XXXXXXXXX`.
 *
 * AUTH-23: логін водія зберігається строго у форматі `+380XXXXXXXXX`
 * (12 цифр після плюса). Ввід у форматах `0XX…`, `380…`, `80…`, з пробілами,
 * дужками, дефісами та нерозривними пробілами нормалізується автоматично —
 * і при створенні акаунта, і при кожному логіні (DRV-06).
 *
 * Критерій приймання 3.3.2: телефон `067 123 45 67` зберігається як
 * `+380671234567`.
 */
final class PhoneNormalizer
{
    /** Код країни України без «+». */
    private const string COUNTRY_CODE = '380';

    /** Довжина національного номера без коду країни (9 цифр: XX XXX XX XX). */
    private const int NATIONAL_LENGTH = 9;

    /**
     * @throws InvalidLoginFormatException якщо номер не піддається нормалізації
     */
    public function normalize(string $raw): string
    {
        $normalized = $this->tryNormalize($raw);

        if (null === $normalized) {
            throw InvalidLoginFormatException::phone($raw);
        }

        return $normalized;
    }

    /** М'який варіант: повертає null замість винятку (використовується у флоу логіну). */
    public function tryNormalize(string $raw): ?string
    {
        $trimmed = trim($raw);

        if ('' === $trimmed) {
            return null;
        }

        // Дозволені «декоративні» символи: пробіли (зокрема нерозривні), дужки,
        // дефіси, крапки та ведучий плюс. Будь-що інше (літери, @ тощо) —
        // це не телефон.
        $withoutDecoration = preg_replace('/[\s\x{00A0}\x{202F}().\-\/]/u', '', $trimmed) ?? '';

        if (!preg_match('/^\+?\d+$/', $withoutDecoration)) {
            return null;
        }

        $hasPlus = str_starts_with($withoutDecoration, '+');
        $digits = ltrim($withoutDecoration, '+');

        $national = $this->extractNationalNumber($digits, $hasPlus);

        if (null === $national) {
            return null;
        }

        return '+'.self::COUNTRY_CODE.$national;
    }

    /** Чи схожий ввід на телефон (а не на email) — для роутингу нормалізації. */
    public function looksLikePhone(string $raw): bool
    {
        return null !== $this->tryNormalize($raw);
    }

    /** Перевірка, що рядок уже в канонічному форматі E.164. */
    public function isCanonical(string $value): bool
    {
        return 1 === preg_match('/^\+'.self::COUNTRY_CODE.'\d{'.self::NATIONAL_LENGTH.'}$/', $value);
    }

    /**
     * Повертає 9 національних цифр або null.
     *
     * Підтримувані форми вводу:
     *  - `+380671234567` / `380671234567` — 12 цифр з кодом країни;
     *  - `0671234567`    — 10 цифр, національний формат із ведучим нулем;
     *  - `80671234567`   — 11 цифр, старий міжміський формат «8-0XX»;
     *  - `671234567`     — 9 цифр, без нуля (часто копіюють із месенджерів).
     */
    private function extractNationalNumber(string $digits, bool $hasPlus): ?string
    {
        $length = \strlen($digits);

        // Із явним «+» приймаємо лише повний міжнародний формат України.
        if ($hasPlus) {
            return 12 === $length && str_starts_with($digits, self::COUNTRY_CODE)
                ? substr($digits, 3)
                : null;
        }

        return match (true) {
            12 === $length && str_starts_with($digits, self::COUNTRY_CODE) => substr($digits, 3),
            11 === $length && str_starts_with($digits, '80') => substr($digits, 2),
            10 === $length && str_starts_with($digits, '0') => substr($digits, 1),
            self::NATIONAL_LENGTH === $length => $digits,
            default => null,
        };
    }
}
