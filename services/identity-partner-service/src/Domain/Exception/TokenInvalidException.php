<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * AUTH_TOKEN_INVALID (401, розділ 3.7).
 *
 * AUTH-02 / AUTH-03: токен, підписаний ключем іншого контуру, або з чужими
 * iss/aud, відхиляється саме цією помилкою — це помилка автентифікації, а не
 * авторизації.
 */
final class TokenInvalidException extends AuthException
{
    public function __construct(private readonly string $technicalReason = '')
    {
        parent::__construct('Помилка автентифікації. Увійдіть повторно.');
    }

    public function errorCode(): string
    {
        return 'AUTH_TOKEN_INVALID';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    /** Технічна причина — тільки для логів, не для користувача (AUTH-53). */
    public function technicalReason(): string
    {
        return $this->technicalReason;
    }
}
