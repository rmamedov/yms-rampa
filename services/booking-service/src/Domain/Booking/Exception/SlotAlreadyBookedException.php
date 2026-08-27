<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;
use App\Domain\Slot\SlotKey;
use Throwable;

/**
 * BOOK-08: на ключ слота вже існує активне бронювання. Джерело істини —
 * частковий унікальний індекс MongoDB (DATA-12); помилка дубліката E11000
 * перетворюється саме на цей виняток.
 */
final class SlotAlreadyBookedException extends ProblemException
{
    public const string ERROR_CODE = 'SLOT_ALREADY_BOOKED';

    public function __construct(public readonly SlotKey $slotKey, ?Throwable $previous = null)
    {
        parent::__construct('Слот щойно забронював інший постачальник', 0, $previous);
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
        return [
            'storeId' => $this->slotKey->storeId,
            'rampId' => $this->slotKey->rampId,
            'slotStart' => $this->slotKey->slotStart->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
