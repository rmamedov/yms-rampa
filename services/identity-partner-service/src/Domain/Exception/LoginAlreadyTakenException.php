<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Логін уже зайнятий (unique-індекс `{login:1}`, 10.6).
 *
 * Крайовий випадок 3.3.2: один і той самий телефон не може належати двом
 * водіям навіть у різних постачальників.
 */
final class LoginAlreadyTakenException extends AuthException
{
    public function __construct(public readonly string $login)
    {
        parent::__construct('Такий логін уже зареєстровано в партнерському контурі.');
    }

    public function errorCode(): string
    {
        return 'PARTNER_ACCOUNT_LOGIN_TAKEN';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function title(): string
    {
        return 'Логін зайнятий';
    }
}
