<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * AUTH-17 / таблиця 3.7: 401 AUTH_2FA_INVALID.
 */
final class TwoFactorInvalidException extends DomainException
{
    public function __construct()
    {
        parent::__construct('AUTH_2FA_INVALID', 401, 'Невірний код підтвердження. Спробуйте ще раз.');
    }
}
