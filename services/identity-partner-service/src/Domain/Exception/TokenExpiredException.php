<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/** AUTH_TOKEN_EXPIRED (401, розділ 3.7); див. також AUTH-44. */
final class TokenExpiredException extends AuthException
{
    public function __construct()
    {
        parent::__construct('Сесія завершилась. Увійдіть повторно.');
    }

    public function errorCode(): string
    {
        return 'AUTH_TOKEN_EXPIRED';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
