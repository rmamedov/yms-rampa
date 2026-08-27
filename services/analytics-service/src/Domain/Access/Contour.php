<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Контур доступу (заголовок X-Contour єдиного контракту ідентичності).
 *
 * staff   — адмін-панель і модуль магазину;
 * partner — кабінет постачальника і застосунок водія.
 *
 * Аналітика живе під /api/admin/v1 і належить виключно staff-контуру.
 */
enum Contour: string
{
    case Staff = 'staff';
    case Partner = 'partner';
}
