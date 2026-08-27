<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Контур доступу (RBAC-03): staff — співробітники мережі, partner — постачальники
 * та їхні водії. Значення збігаються із заголовком X-Contour, який підставляє
 * api-gateway з відповіді identity-сервісу.
 */
enum Contour: string
{
    case Staff = 'staff';
    case Partner = 'partner';
}
