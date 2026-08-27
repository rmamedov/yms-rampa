<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Shared\DomainException;

/**
 * RBAC-25: у системі повинен існувати щонайменше один активний super_admin.
 *
 * Таблиця 4.10, сценарій 10: 409 RBAC_LAST_SUPER_ADMIN.
 */
final class LastSuperAdminException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'RBAC_LAST_SUPER_ADMIN',
            409,
            'Останнього адміністратора деактивувати не можна',
        );
    }
}
