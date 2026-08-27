<?php

declare(strict_types=1);

namespace App\Domain\Vehicle;

use App\Domain\Shared\ValidationException;

/**
 * Нормалізація держномера авто (SUP-BOOK-02, SUP-BOOK-03, DATA-18).
 *
 * Правила: верхній регістр, повне прибирання пробілів і роздільників,
 * довжина 4–12 символів, дозволені латиниця, кирилиця та цифри.
 * Нормалізація обов'язкова ДО перевірки унікальності: «AA 1234 BB»
 * і «aa1234bb» — той самий номер у межах одного постачальника.
 */
final class PlateNumberNormalizer
{
    /** Дозволені символи після нормалізації: латиниця, українська кирилиця, цифри. */
    private const PATTERN = '/^[A-ZА-ЩЬЮЯЄІЇҐ0-9]{4,12}$/u';

    private const MIN_LENGTH = 4;
    private const MAX_LENGTH = 12;

    /**
     * @throws ValidationException якщо номер порожній або не відповідає формату
     */
    public static function normalize(string $raw): string
    {
        // Прибираємо пробіли будь-якого виду, дефіси, крапки та підкреслення.
        $compact = preg_replace('/[\s\-_.]+/u', '', trim($raw)) ?? '';

        if ('' === $compact) {
            throw new ValidationException('Вкажіть держномер авто.', 'VEHICLE_PLATE_REQUIRED');
        }

        $upper = mb_strtoupper($compact, 'UTF-8');
        $length = mb_strlen($upper, 'UTF-8');

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new ValidationException(
                \sprintf(
                    'Держномер має містити від %d до %d символів, отримано %d.',
                    self::MIN_LENGTH,
                    self::MAX_LENGTH,
                    $length,
                ),
                'VEHICLE_PLATE_INVALID',
            );
        }

        if (1 !== preg_match(self::PATTERN, $upper)) {
            throw new ValidationException(
                \sprintf('Держномер «%s» містить недопустимі символи.', trim($raw)),
                'VEHICLE_PLATE_INVALID',
            );
        }

        return $upper;
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
