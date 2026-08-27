<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * Бронювання не існує або недоступне цьому актору.
 */
final class BookingNotFoundException extends ProblemException
{
    public const string ERROR_CODE = 'BOOKING_NOT_FOUND';

    public function __construct(public readonly string $bookingId)
    {
        parent::__construct('Бронювання не знайдено');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
