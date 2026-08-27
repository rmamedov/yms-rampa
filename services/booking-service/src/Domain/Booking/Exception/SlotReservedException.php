<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * GRID-04 / BOOK-05: слот закріплений розкладом резервів за іншим
 * постачальником. За ким саме — клієнту не розкривається.
 */
final class SlotReservedException extends ProblemException
{
    public const string ERROR_CODE = 'SLOT_RESERVED';

    public function __construct()
    {
        parent::__construct('Слот зарезервовано за іншим постачальником');
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
