<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * BOOK-04: попередження (не блокер) про перетин у часі бронювань того самого
 * `plateNumber` у межах ОДНОГО постачальника — незалежно від магазину.
 *
 * Клієнт обходить попередження повторним запитом з `confirmConflict=true`.
 * Сусідні непересічні слоти того самого авто — легальний сценарій (EDGE-01).
 */
final class VehicleTimeConflictException extends ProblemException
{
    public const string ERROR_CODE = 'VEHICLE_TIME_CONFLICT';

    /**
     * @param list<array<string, mixed>> $conflicts деталі бронювань, що перетинаються
     */
    public function __construct(
        public readonly string $plateNumber,
        public readonly array $conflicts,
    ) {
        parent::__construct(\sprintf(
            'Авто %s уже має бронювання, що перетинається за часом. Підтвердіть, щоб продовжити',
            $plateNumber,
        ));
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    /** Це попередження, а не відмова: клієнт повторює запит з confirmConflict=true. */
    public function httpStatus(): int
    {
        return 409;
    }

    public function problemExtensions(): array
    {
        return [
            'warning' => true,
            'plateNumber' => $this->plateNumber,
            'conflicts' => $this->conflicts,
            'resolution' => 'Повторіть запит з confirmConflict=true, щоб підтвердити бронювання',
        ];
    }
}
