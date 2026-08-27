<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * EDIT-02: постачальник змінює або скасовує бронювання пізніше ніж за
 * editDeadlineHours до slotStart. Магазин і адмін дедлайном не обмежені;
 * зміна водія/авто без зміни слота — виняток (EDIT-05).
 */
final class EditDeadlinePassedException extends ProblemException
{
    public const string ERROR_CODE = 'EDIT_DEADLINE_PASSED';

    public function __construct(public readonly int $editDeadlineHours)
    {
        parent::__construct(\sprintf(
            'Зміни можливі не пізніше ніж за %d год до слоту. Звʼяжіться з магазином',
            $editDeadlineHours,
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
        return ['editDeadlineHours' => $this->editDeadlineHours];
    }
}
