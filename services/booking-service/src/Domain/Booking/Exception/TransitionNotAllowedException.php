<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Access\Role;
use App\Domain\Booking\BookingStatus;
use App\Domain\Exception\ProblemException;

/**
 * Перехід сам по собі дозволений машиною станів, але роль ініціатора не має
 * права його виконувати (колонка «Хто виконує» таблиці ST-01..ST-07).
 */
final class TransitionNotAllowedException extends ProblemException
{
    public const string ERROR_CODE = 'TRANSITION_NOT_ALLOWED';

    public function __construct(
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
        public readonly Role $role,
    ) {
        parent::__construct(\sprintf(
            'Роль «%s» не має права виконати перехід «%s» → «%s»',
            $role->value,
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
        return 403;
    }

    public function problemExtensions(): array
    {
        return ['from' => $this->from->value, 'to' => $this->to->value, 'role' => $this->role->value];
    }
}
