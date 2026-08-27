<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * BOOK-09 (анти-сквотинг): у постачальника вже maxActiveBookingsPerSupplier
 * активних майбутніх бронювань. Walk-in магазину в ліміт не входять.
 */
final class BookingLimitExceededException extends ProblemException
{
    public const string ERROR_CODE = 'BOOKING_LIMIT_EXCEEDED';

    public function __construct(public readonly int $limit)
    {
        parent::__construct(\sprintf(
            'Досягнуто ліміт активних бронювань (%d). Скасуйте неактуальні бронювання або дочекайтеся виконання поточних',
            $limit,
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
        return ['limit' => $this->limit];
    }
}
