<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * AUTH_REFRESH_REUSED (401, розділ 3.7).
 *
 * AUTH-31: повторне використання вже погашеного refresh-токена трактується як
 * детекція крадіжки — відкликається весь ланцюжок sid.
 */
final class RefreshTokenReusedException extends AuthException
{
    public function __construct()
    {
        parent::__construct('З міркувань безпеки всі сесії завершено. Увійдіть повторно.');
    }

    public function errorCode(): string
    {
        return 'AUTH_REFRESH_REUSED';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
