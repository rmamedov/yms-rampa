<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\Role;
use App\Domain\Shared\DomainException;

/**
 * RBAC-23: призначення ролі поза деревом 4.7 (напр. network_manager → super_admin).
 *
 * Таблиця 4.10, сценарій 7: 403 RBAC_ROLE_ASSIGNMENT_FORBIDDEN.
 */
final class RoleAssignmentForbiddenException extends DomainException
{
    public function __construct(Role $actorRole, Role $targetRole)
    {
        parent::__construct(
            'RBAC_ROLE_ASSIGNMENT_FORBIDDEN',
            403,
            'Ви не можете призначити цю роль',
            ['actorRole' => $actorRole->value, 'targetRole' => $targetRole->value],
        );
    }
}
