<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;
use App\Domain\Slot\SlotState;

/**
 * Слот існує в сітці, але його поточний стан не дозволяє бронювання:
 * blocked, past (lead time GRID-02) або слот поза вікном прийому.
 *
 * Для walk-in (WALK-03) стан `past` у межах поточної дати допустимий,
 * тому lead time для нього не перевіряється.
 */
final class SlotNotAvailableException extends ProblemException
{
    public const string ERROR_CODE = 'SLOT_NOT_AVAILABLE';

    public function __construct(public readonly ?SlotState $state = null, string $message = 'Цей слот недоступний для бронювання')
    {
        parent::__construct($message);
    }

    public static function outsideGrid(): self
    {
        return new self(null, 'Такого слота немає в сітці магазину на цю дату');
    }

    public static function leadTime(int $leadTimeMinutes): self
    {
        return new self(SlotState::Past, \sprintf(
            'Бронювання можливе не пізніше ніж за %d хв до початку слоту',
            $leadTimeMinutes,
        ));
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
        return null === $this->state ? [] : ['slotState' => $this->state->value];
    }
}
