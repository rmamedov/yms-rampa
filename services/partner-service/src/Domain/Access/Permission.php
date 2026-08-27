<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Права, які перевіряє САМЕ partner-service, і відповідні рядки матриці
 * «ролі × права» з розділу 4.4 SRS (RBAC-10), перенесені ДОСЛІВНО.
 *
 * Джерело істини — та сама таблиця, що й у identity-staff-service
 * (App\Domain\Identity\PermissionMatrix); тут лише чотири її рядки, які
 * стосуються ендпоїнтів цього сервісу:
 *
 *   supplier.read   — GET /api/admin/v1/suppliers[/{id}]
 *   supplier.manage — POST/PATCH/DELETE /api/admin/v1/suppliers…
 *   driver.manage   — /api/supplier/v1/drivers…
 *   vehicle.manage  — /api/supplier/v1/vehicles…
 *
 * RBAC-02: окремого права «читати водіїв/авто» в матриці немає, тому розділ
 * цілком закривається відповідним *.manage — усе, чого немає в таблиці,
 * заборонено.
 */
enum Permission: string
{
    case SupplierRead = 'supplier.read';
    case SupplierManage = 'supplier.manage';
    case DriverManage = 'driver.manage';
    case VehicleManage = 'vehicle.manage';

    /**
     * Порядок колонок таблиці 4.4.
     *
     * @var list<string>
     */
    private const ROLE_ORDER = [
        'super_admin',
        'network_manager',
        'store_manager',
        'store_operator',
        'analyst',
        'supplier_admin',
        'supplier_operator',
        'driver',
    ];

    /**
     * Рядки таблиці 4.4 як є: 8 символів у порядку ROLE_ORDER.
     *
     * @var array<string, list<string>>
     */
    private const ROWS = [
        'supplier.read' => ['✓', '✓', '—', '—', '✓', 'S', '—', '—'],
        'supplier.manage' => ['✓', '✓', '—', '—', '—', '—', '—', '—'],
        'driver.manage' => ['—', '—', '—', '—', '—', 'S', '—', '—'],
        'vehicle.manage' => ['—', '—', '—', '—', '—', 'S', 'S', '—'],
    ];

    public function grantFor(Role $role): PermissionGrant
    {
        $index = array_search($role->value, self::ROLE_ORDER, true);

        if (!\is_int($index)) {
            throw new \LogicException(\sprintf('Роль «%s» відсутня в матриці 4.4.', $role->value));
        }

        return PermissionGrant::fromSymbol(self::ROWS[$this->value][$index]);
    }
}
