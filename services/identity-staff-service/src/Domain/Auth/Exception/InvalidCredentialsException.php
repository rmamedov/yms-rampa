<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * Таблиця 3.7: 401 AUTH_INVALID_CREDENTIALS.
 *
 * AUTH-53: текст не розкриває, що саме невірне — логін чи пароль,
 * і не підтверджує існування облікового запису.
 */
final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('AUTH_INVALID_CREDENTIALS', 401, 'Невірний логін або пароль.');
    }
}
