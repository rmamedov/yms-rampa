<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * Таблиця 3.7: 401 AUTH_TOKEN_EXPIRED.
 */
final class TokenExpiredException extends DomainException
{
    public function __construct()
    {
        parent::__construct('AUTH_TOKEN_EXPIRED', 401, 'Сесія завершилась. Увійдіть повторно.');
    }
}
