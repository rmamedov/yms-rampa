<?php

declare(strict_types=1);

namespace App\Domain\Hold\Exception;

use App\Domain\Exception\ProblemException;

/**
 * HOLD-01: слот зараз оформлює інший користувач.
 */
final class SlotHeldException extends ProblemException
{
    public const string ERROR_CODE = 'SLOT_HELD';

    public function __construct()
    {
        parent::__construct('Слот зараз оформлює інший користувач. Спробуйте за кілька хвилин');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
