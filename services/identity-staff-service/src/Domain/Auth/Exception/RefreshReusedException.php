<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * AUTH-31 / таблиця 3.7: 401 AUTH_REFRESH_REUSED.
 *
 * Повторне використання вже погашеного refresh-токена трактується як
 * детекція крадіжки: весь ланцюжок sid відкликається.
 */
final class RefreshReusedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('AUTH_REFRESH_REUSED', 401, 'З міркувань безпеки всі сесії завершено. Увійдіть повторно.');
    }
}
