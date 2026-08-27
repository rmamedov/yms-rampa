<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\Permission;
use App\Domain\Shared\DomainException;

/**
 * RBAC-02 / таблиця 4.10, сценарій 3: роль не має права за матрицею 4.4.
 */
final class PermissionDeniedException extends DomainException
{
    public function __construct(Permission $permission)
    {
        parent::__construct(
            'RBAC_PERMISSION_DENIED',
            403,
            'У вас немає прав для цієї дії',
            ['permission' => $permission->value],
        );
    }
}
