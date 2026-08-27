<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use DomainException;

/**
 * Запит сітки на дату поза горизонтом бронювання (GRID-03).
 * На рівні HTTP перетворюється на 422 з кодом DATE_OUT_OF_HORIZON.
 */
final class DateOutOfHorizonException extends DomainException
{
    public const string ERROR_CODE = 'DATE_OUT_OF_HORIZON';

    public function __construct(public readonly int $horizonDays)
    {
        parent::__construct(\sprintf('Бронювання доступне не далі ніж на %d днів вперед', $horizonDays));
    }
}
