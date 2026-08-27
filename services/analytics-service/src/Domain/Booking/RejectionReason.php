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

        return self::tryFrom($code) ?? self::fromPublisherValue($code) ?? self::Other;
    }

    /**
     * Значення, якими причину називає ВИДАВЕЦЬ події (booking-service,
     * App\Domain\Booking\RejectionReason).
     *
     * Там довідник зберігається людськими назвами українською — вони лежать
     * і в документах бронювань, і в публічному API, і в листі NOT-T8, тому
     * переписати їх на коди неможливо. Зіставлення живе тут, на боці
     * споживача: без нього КОЖНА причина мапилася б на self::Other і розріз
     * причин відмов складався б з єдиної групи «Інше».
     */
    private static function fromPublisherValue(string $value): ?self
    {
        return match (mb_strtolower(trim($value), 'UTF-8')) {
            'перевищення тоннажу' => self::WeightExceeded,
            'невідповідність вантажу' => self::CargoMismatch,
            'відсутні документи' => self::DocumentsMissing,
            'інше' => self::Other,
            default => null,
        };
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
