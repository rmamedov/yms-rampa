<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * Кількість палет поза діапазоном 1..33 (розділ 6.4). Поле обовʼязкове.
 */
final class PalletsOutOfRangeException extends ProblemException
{
    public const string ERROR_CODE = 'PALLETS_OUT_OF_RANGE';
    public const int MIN = 1;
    public const int MAX = 33;

    public function __construct(public readonly int $palletsCount)
    {
        parent::__construct(\sprintf(
            'Кількість палет має бути в діапазоні %d..%d, отримано %d',
            self::MIN,
            self::MAX,
            $palletsCount,
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
}
