<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * AUTH-12 / таблиця 3.7: 403 AUTH_ACCOUNT_DISABLED — деактивований запис
 * не проходить автентифікацію навіть із правильним паролем.
 */
final class AccountDisabledException extends DomainException
{
    public function __construct()
    {
        parent::__construct('AUTH_ACCOUNT_DISABLED', 403, 'Обліковий запис деактивовано. Зверніться до адміністратора.');
    }
}
