<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Контур доступу (заголовок X-Contour єдиного контракту ідентичності).
 *
 * staff   — адмін-панель і модуль магазину (identity-staff-service);
 * partner — кабінет постачальника і застосунок водія (identity-partner-service).
 *
 * partner-service обслуговує обидва контури: /api/admin/v1/suppliers — staff,
 * /api/supplier/v1/{vehicles,drivers} — partner.
 */
enum Contour: string
{
    case Staff = 'staff';
    case Partner = 'partner';
}
