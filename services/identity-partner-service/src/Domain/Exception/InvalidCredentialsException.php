<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * AUTH_INVALID_CREDENTIALS (401, розділ 3.7).
 *
 * Єдина помилка і для неіснуючого логіна, і для невірного пароля, і для
 * спроби зайти чужим застосунком — щоб не розкривати існування акаунта
 * (AUTH-53, крайовий випадок 3.6).
 */
final class InvalidCredentialsException extends AuthException
{
    public function __construct()
    {
        parent::__construct('Невірний логін або пароль.');
    }

    public function errorCode(): string
    {
        return 'AUTH_INVALID_CREDENTIALS';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
