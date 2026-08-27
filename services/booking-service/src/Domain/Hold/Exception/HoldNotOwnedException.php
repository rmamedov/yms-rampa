<?php

declare(strict_types=1);

namespace App\Domain\Hold\Exception;

use App\Domain\Exception\ProblemException;

/**
 * HOLD-03: підтвердити бронювання або продовжити холд може лише його власник.
 */
final class HoldNotOwnedException extends ProblemException
{
    public const string ERROR_CODE = 'HOLD_NOT_OWNED';

    public function __construct()
    {
        parent::__construct('Цей холд слота належить іншому користувачу');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
