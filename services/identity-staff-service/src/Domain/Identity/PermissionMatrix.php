<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Матриця «ролі × права» з розділу 4.4 SRS, перенесена ДОСЛІВНО (RBAC-10).
 *
 * Рядки — права у порядку таблиці 4.3, колонки — ролі у порядку таблиці 4.4.
 * Легенда: ✓ повне право в межах контуру, S — лише в межах скоупа (4.5),
 * P — лише публічні атрибути активних магазинів, — заборонено.
 *
 * RBAC-05: матриця постачається як версіонований конфіг; версія нижче
 * публікується сервісом, щоб моніторинг фіксував розбіжність між сервісами.
 */
final class PermissionMatrix
{
    /**
     * RBAC-05: версія матриці; змінюється разом із будь-якою правкою таблиці 4.4.
     */
    public const string VERSION = '1.0.0';

    /**
     * Порядок колонок таблиці 4.4.
     *
     * @var list<string>
     */
    private const array ROLE_ORDER = [
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
     * Таблиця 4.4 як є. Ключ — значення Permission, значення — 8 символів у порядку ROLE_ORDER.
     *
     * `S*` для network_manager у users.manage.staff / roles.assign означає обмеження
     * деревом призначення з 4.7 (лише store_manager і store_operator), а не скоупом магазинів.
     *
     * @var array<string, list<string>>
     */
    private const array ROWS = [
        'store.read' => ['✓', '✓', 'S', 'S', '✓', 'P', 'P', 'P'],
        'store.configure' => ['✓', '✓', '—', '—', '—', '—', '—', '—'],
        'store.sync.manage' => ['✓', '✓', '—', '—', '—', '—', '—', '—'],
        'slot.read' => ['✓', '✓', 'S', 'S', '✓', 'P', 'P', '—'],
        'slot.block' => ['✓', '✓', 'S', '—', '—', '—', '—', '—'],
        'slot.reserve' => ['✓', '✓', '—', '—', '—', '—', '—', '—'],
        'booking.create' => ['—', '—', '—', '—', '—', 'S', 'S', '—'],
        'booking.create_walk_in' => ['✓', '✓', 'S', 'S', '—', '—', '—', '—'],
        'booking.read.all' => ['✓', '✓', 'S', 'S', '✓', '—', '—', '—'],
        'booking.read.own' => ['—', '—', '—', '—', '—', 'S', 'S', 'S'],
        'booking.update.own' => ['—', '—', '—', '—', '—', 'S', 'S', '—'],
        'booking.cancel.own' => ['—', '—', '—', '—', '—', 'S', 'S', '—'],
        'booking.cancel.any' => ['✓', '✓', 'S', '—', '—', '—', '—', '—'],
        'booking.mark_arrived' => ['✓', '—', 'S', 'S', '—', '—', '—', 'S'],
        'booking.mark_unloading' => ['✓', '—', 'S', 'S', '—', '—', '—', '—'],
        'booking.mark_unloaded' => ['✓', '—', 'S', 'S', '—', '—', '—', '—'],
        'booking.mark_no_show' => ['✓', '—', 'S', 'S', '—', '—', '—', '—'],
        'booking.mark_delayed' => ['✓', '—', 'S', 'S', '—', '—', '—', 'S'],
        'booking.reject' => ['✓', '✓', 'S', 'S', '—', '—', '—', '—'],
        'booking.reassign_ramp' => ['✓', '✓', 'S', 'S', '—', '—', '—', '—'],
        'routesheet.read.own' => ['—', '—', '—', '—', '—', 'S', 'S', 'S'],
        'routesheet.manage' => ['—', '—', '—', '—', '—', 'S', 'S', '—'],
        'supplier.read' => ['✓', '✓', '—', '—', '✓', 'S', '—', '—'],
        'supplier.manage' => ['✓', '✓', '—', '—', '—', '—', '—', '—'],
        'driver.manage' => ['—', '—', '—', '—', '—', 'S', '—', '—'],
        'vehicle.manage' => ['—', '—', '—', '—', '—', 'S', 'S', '—'],
        'analytics.view' => ['✓', '✓', 'S', '—', '✓', '—', '—', '—'],
        'users.manage.staff' => ['✓', 'S*', '—', '—', '—', '—', '—', '—'],
        'users.manage.supplier' => ['✓', '✓', '—', '—', '—', 'S', '—', '—'],
        'roles.assign' => ['✓', 'S*', '—', '—', '—', 'S', '—', '—'],
        'audit.read' => ['✓', '✓', '—', '—', '—', '—', '—', '—'],
    ];

    /** @var array<string, array<string, PermissionGrant>>|null */
    private static ?array $compiled = null;

    private function __construct()
    {
    }

    /**
     * RBAC-02: deny by default — усе, чого немає в матриці, заборонено.
     */
    public static function grant(Role $role, Permission $permission): PermissionGrant
    {
        return self::compiled()[$role->value][$permission->value] ?? PermissionGrant::Denied;
    }

    /**
     * Усі права ролі, крім заборонених.
     *
     * @return list<Permission>
     */
    public static function permissionsOf(Role $role): array
    {
        $result = [];
        foreach (Permission::cases() as $permission) {
            if (self::grant($role, $permission)->isGranted()) {
                $result[] = $permission;
            }
        }

        return $result;
    }

    /**
     * Символи таблиці 4.4 для одного права — для дампу матриці в консолі.
     *
     * @return array<string, string>
     */
    public static function rowOf(Permission $permission): array
    {
        $row = [];
        foreach (self::ROLE_ORDER as $roleValue) {
            $row[$roleValue] = self::grant(Role::from($roleValue), $permission)->symbol();
        }

        return $row;
    }

    /**
     * @return array<string, array<string, PermissionGrant>>
     */
    private static function compiled(): array
    {
        if (null !== self::$compiled) {
            return self::$compiled;
        }

        $compiled = [];
        foreach (self::ROWS as $permissionValue => $symbols) {
            if (\count($symbols) !== \count(self::ROLE_ORDER)) {
                throw new \LogicException(\sprintf(
                    'Рядок матриці "%s" містить %d символів замість %d.',
                    $permissionValue,
                    \count($symbols),
                    \count(self::ROLE_ORDER),
                ));
            }

            foreach ($symbols as $index => $symbol) {
                $compiled[self::ROLE_ORDER[$index]][$permissionValue] = PermissionGrant::fromSymbol($symbol);
            }
        }

        return self::$compiled = $compiled;
    }
}
