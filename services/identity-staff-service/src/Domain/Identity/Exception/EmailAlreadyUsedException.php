<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Shared\DomainException;

/**
 * Порушення унікального індексу `{email:1}` колекції `staff_users` (10.5).
 *
 * Код USER_EMAIL_ALREADY_EXISTS — розширення таблиці 4.10 (SRS не називає
 * коду для цього випадку); статус 409 обрано як конфлікт стану.
 */
final class EmailAlreadyUsedException extends DomainException
{
    public function __construct(string $email)
    {
        parent::__construct(
            'USER_EMAIL_ALREADY_EXISTS',
            409,
            'Користувач з таким email вже існує.',
            ['email' => $email],
        );
    }
}
