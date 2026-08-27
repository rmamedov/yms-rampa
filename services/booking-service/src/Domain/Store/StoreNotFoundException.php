<?php

declare(strict_types=1);

namespace App\Domain\Store;

use App\Domain\Exception\ProblemException;

/**
 * GRID-01, крок 2: магазину немає або його ymsStatus ≠ active —
 * для контуру постачальника це 404 без розкриття причини.
 */
final class StoreNotFoundException extends ProblemException
{
    public const string ERROR_CODE = 'STORE_NOT_FOUND';

    public function __construct(public readonly string $storeId)
    {
        parent::__construct('Філію не знайдено або вона не підключена до системи');
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
