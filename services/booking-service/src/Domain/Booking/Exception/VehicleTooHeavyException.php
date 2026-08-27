<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * BOOK-01: маса авто перевищує ліміт філії. Єдиний код помилки тоннажу
 * в системі — VEHICLE_TOO_HEAVY (перевіряється і при видачі сітки, і
 * обовʼязково на сервері при підтвердженні, і при заміні авто EDIT-05).
 */
final class VehicleTooHeavyException extends ProblemException
{
    public const string ERROR_CODE = 'VEHICLE_TOO_HEAVY';

    public function __construct(
        public readonly float $maxVehicleWeightTons,
        public readonly float $actualWeightTons,
    ) {
        parent::__construct(\sprintf(
            'Ця філія приймає авто до %s т',
            rtrim(rtrim(number_format($maxVehicleWeightTons, 1, '.', ''), '0'), '.'),
        ));
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function problemExtensions(): array
    {
        return [
            'maxVehicleWeightTons' => $this->maxVehicleWeightTons,
            'actualWeightTons' => $this->actualWeightTons,
        ];
    }
}
