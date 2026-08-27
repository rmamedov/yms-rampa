<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Довідник причин відмови в прийомі (глосарій 1.5, статус rejected).
 *
 * Невідомий код причини мапиться на self::Other, щоб read-модель
 * не ламалася на подіях із розширеним довідником.
 */
enum RejectionReason: string
{
    case WeightExceeded = 'weight_exceeded';
    case CargoMismatch = 'cargo_mismatch';
    case DocumentsMissing = 'documents_missing';
    case Other = 'other';

    public static function fromCode(?string $code): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::tryFrom($code) ?? self::Other;
    }

    public function label(): string
    {
        return match ($this) {
            self::WeightExceeded => 'Перевищення тоннажу',
            self::CargoMismatch => 'Невідповідність вантажу',
            self::DocumentsMissing => 'Відсутні документи',
            self::Other => 'Інше',
        };
    }
}
