<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Booking\BookingStatus;
use App\Domain\Exception\ProblemException;

/**
 * ST-06: будь-який перехід поза машиною станів (напр. completed → unloading)
 * заборонений і повертає 409 INVALID_STATUS_TRANSITION.
 */
final class InvalidStatusTransitionException extends ProblemException
{
    public const string ERROR_CODE = 'INVALID_STATUS_TRANSITION';

    public function __construct(
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
    ) {
        parent::__construct(\sprintf(
            'Перехід зі статусу «%s» у «%s» неможливий',
            $from->value,
            $to->value,
        ));
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function problemExtensions(): array
    {
        return ['from' => $this->from->value, 'to' => $this->to->value];
    }
}
