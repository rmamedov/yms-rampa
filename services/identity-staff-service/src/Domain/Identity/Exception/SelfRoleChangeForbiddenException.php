<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Shared\DomainException;

/**
 * RBAC-24: користувач не може змінювати власну роль, власний скоуп
 * і не може деактивувати власний акаунт.
 *
 * Таблиця 4.10, сценарій 8: 403 RBAC_SELF_ROLE_CHANGE_FORBIDDEN.
 */
final class SelfRoleChangeForbiddenException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'RBAC_SELF_ROLE_CHANGE_FORBIDDEN',
            403,
            'Не можна змінювати власну роль',
        );
    }
}
