<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

/**
 * Дії, що фіксуються в `role_audit` (RBAC-29).
 */
enum RoleAuditAction: string
{
    case Create = 'create';
    case Assign = 'assign';
    case ScopeChange = 'scope_change';
    case Deactivate = 'deactivate';
    case Reactivate = 'reactivate';
}
