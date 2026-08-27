<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Довідник причин часткового розвантаження (ST-03).
 */
enum PartialUnloadReason: string
{
    case NoSpace = 'немає місця';
    case Damaged = 'бій/брак';
    case OrderMismatch = 'розбіжність із замовленням';
    case PartialRefusal = 'відмова частини вантажу';
    case Other = 'інше';

    public function requiresComment(): bool
    {
        return self::Other === $this;
    }
}
