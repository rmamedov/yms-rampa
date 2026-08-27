<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * Держномер поза допустимою довжиною 4–12 символів (розділ 6.4).
 */
final class InvalidPlateNumberException extends ProblemException
{
    public const string ERROR_CODE = 'INVALID_PLATE_NUMBER';

    public function __construct(public readonly string $plateNumber)
    {
        parent::__construct('Держномер має містити від 4 до 12 символів');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
