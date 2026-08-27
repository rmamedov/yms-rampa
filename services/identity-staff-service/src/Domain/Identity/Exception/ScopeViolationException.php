<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\Permission;
use App\Domain\Shared\DomainException;

/**
 * RBAC-18 / таблиця 4.10, сценарій 4: право є, але ресурс поза скоупом
 * користувача — для дії/запису повертається 403 RBAC_SCOPE_VIOLATION.
 */
final class ScopeViolationException extends DomainException
{
    public function __construct(Permission $permission, ?string $storeId = null)
    {
        parent::__construct(
            'RBAC_SCOPE_VIOLATION',
            403,
            'Дія недоступна для цього магазину',
            ['permission' => $permission->value, 'storeId' => $storeId],
        );
    }
}
