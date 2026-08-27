<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Функціонал заплановано на фазу 2 і поза межами MVP.
 *
 * Використовується, зокрема, каналом Viber (NOT-01).
 */
final class NotImplementedException extends DomainException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'NOT_IMPLEMENTED', 501, $previous);
    }
}
