<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Shared\DomainException;

/**
 * RBAC-27.1 / DATA-36: спроба призначити користувачу другу роль.
 *
 * Таблиця 4.10, сценарій 9: 422 RBAC_MULTIPLE_ROLES_FORBIDDEN.
 */
final class MultipleRolesForbiddenException extends DomainException
{
    /**
     * @param list<string> $requestedRoles
     */
    public function __construct(array $requestedRoles = [])
    {
        parent::__construct(
            'RBAC_MULTIPLE_ROLES_FORBIDDEN',
            422,
            'Користувач може мати лише одну роль',
            ['requestedRoles' => array_values($requestedRoles)],
        );
    }
}
