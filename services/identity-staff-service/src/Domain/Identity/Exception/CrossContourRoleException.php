<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\Role;
use App\Domain\Shared\DomainException;

/**
 * RBAC-27.2 / RBAC-27.4: крос-контурна комбінація — акаунт staff-контуру
 * не може отримати partner-роль і навпаки. Контури мають окремі колекції
 * користувачів; збіг email трактується як різні особи (AUTH-04).
 *
 * Код RBAC_CROSS_CONTOUR_ROLE_FORBIDDEN — розширення таблиці 4.10, яка
 * не називає окремого коду для цього інваріанта; HTTP-статус узгоджено з
 * сценарієм 9 (422), бо це така сама відмова на рівні identity-сервісу.
 */
final class CrossContourRoleException extends DomainException
{
    public function __construct(Role $role)
    {
        parent::__construct(
            'RBAC_CROSS_CONTOUR_ROLE_FORBIDDEN',
            422,
            'Роль іншого контуру не може бути призначена співробітнику мережі',
            ['role' => $role->value, 'contour' => $role->contour()->value],
        );
    }
}
