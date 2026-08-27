<?php

declare(strict_types=1);

namespace App\Domain\Hold\Exception;

use App\Domain\Exception\ProblemException;

/**
 * HOLD-02: TTL холду протух або вичерпано сумарний ліміт holdMaxMinutes.
 */
final class HoldExpiredException extends ProblemException
{
    public const string ERROR_CODE = 'HOLD_EXPIRED';

    public function __construct()
    {
        parent::__construct('Час оформлення вичерпано, оновіть сітку');
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
