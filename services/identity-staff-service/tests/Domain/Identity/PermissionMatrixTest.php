<?php

declare(strict_types=1);

namespace App\Tests\Domain\Identity;

use App\Domain\Identity\Contour;
use App\Domain\Identity\Permission;
use App\Domain\Identity\PermissionGrant;
use App\Domain\Identity\PermissionMatrix;
use App\Domain\Identity\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Контрактний тест матриці 4.4 (RBAC-10, критерій RBAC-AC-01):
 * перебирає ВСІ пари «роль × право» і звіряє з таблицею SRS.
 */
#[CoversClass(PermissionMatrix::class)]
#[CoversClass(Role::class)]
final class PermissionMatrixTest extends TestCase
{
    /**
     * Таблиця 4.4 SRS, продубльована незалежно від реалізації:
     * ключ — право, значення — символи у порядку колонок таблиці.
     *
     * @return array<string, list<string>>
     */
    private static function srsTable(): array
    {
        return [
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
    }

    /**
     * @return \Generator<string, array{Role, Permission, PermissionGrant}>
     */
    public static function matrixProvider(): \Generator
    {
        $roles = Role::cases();

        foreach (self::srsTable() as $permissionValue => $symbols) {
            $permission = Permission::from($permissionValue);

            foreach ($symbols as $index => $symbol) {
                $role = $roles[$index];

                yield \sprintf('%s × %s = %s', $role->value, $permissionValue, $symbol) => [
                    $role,
                    $permission,
                    PermissionGrant::fromSymbol($symbol),
                ];
            }
        }
    }

    #[DataProvider('matrixProvider')]
    public function testMatrixMatchesSrsTable(Role $role, Permission $permission, PermissionGrant $expected): void
    {
        self::assertSame(
            $expected,
            $role->grantFor($permission),
            \sprintf('Розбіжність із таблицею 4.4 для %s / %s', $role->value, $permission->value),
        );
    }

    /**
     * RBAC-08: кожне право каталогу 4.3 присутнє в матриці і навпаки.
     */
    public function testEveryPermissionFromCatalogHasMatrixRow(): void
    {
        $catalog = array_map(static fn (Permission $p): string => $p->value, Permission::cases());

        self::assertSame(
            array_values($catalog),
            array_keys(self::srsTable()),
            'Каталог прав 4.3 і рядки матриці 4.4 мають збігатися один в один.',
        );
    }

    /**
     * RBAC-07: super_admin має «✓» у кожному staff-рядку матриці —
     * тобто в кожному праві, яке надано бодай одній staff-ролі.
     */
    public function testSuperAdminHasFullGrantOnEveryStaffPermission(): void
    {
        $staffRoles = Role::staffRoles();

        foreach (Permission::cases() as $permission) {
            $grantedToAnyStaffRole = false;

            foreach ($staffRoles as $role) {
                if ($role->grantFor($permission)->isGranted()) {
                    $grantedToAnyStaffRole = true;

                    break;
                }
            }

            if (!$grantedToAnyStaffRole) {
                continue;
            }

            self::assertSame(
                PermissionGrant::Full,
                Role::SuperAdmin->grantFor($permission),
                \sprintf('RBAC-07: super_admin повинен мати ✓ для "%s".', $permission->value),
            );
        }
    }

    /**
     * RBAC-07: суто партнерські права недоступні жодній staff-ролі.
     */
    public function testPartnerOnlyPermissionsAreDeniedToEveryStaffRole(): void
    {
        $partnerOnly = [
            Permission::BookingCreate,
            Permission::BookingReadOwn,
            Permission::BookingUpdateOwn,
            Permission::BookingCancelOwn,
            Permission::RouteSheetReadOwn,
            Permission::RouteSheetManage,
            Permission::DriverManage,
            Permission::VehicleManage,
        ];

        foreach ($partnerOnly as $permission) {
            foreach (Role::staffRoles() as $role) {
                self::assertSame(
                    PermissionGrant::Denied,
                    $role->grantFor($permission),
                    \sprintf('Право "%s" не має бути доступним ролі "%s".', $permission->value, $role->value),
                );
            }
        }
    }

    /**
     * RBAC-02: право, якого немає в матриці для ролі, заборонене за замовчуванням.
     */
    public function testAnalystHasNoWritePermissions(): void
    {
        $writes = [
            Permission::StoreConfigure,
            Permission::SlotBlock,
            Permission::SlotReserve,
            Permission::BookingCreateWalkIn,
            Permission::BookingReject,
            Permission::BookingMarkUnloaded,
            Permission::UsersManageStaff,
            Permission::RolesAssign,
        ];

        foreach ($writes as $permission) {
            self::assertSame(PermissionGrant::Denied, Role::Analyst->grantFor($permission));
        }

        // Аналітик має доступ лише на читання (4.2)
        self::assertSame(PermissionGrant::Full, Role::Analyst->grantFor(Permission::AnalyticsView));
        self::assertSame(PermissionGrant::Full, Role::Analyst->grantFor(Permission::BookingReadAll));
    }

    /**
     * RBAC-03/RBAC-06: контур кожної ролі відповідає таблиці 4.2.
     */
    public function testRoleContoursMatchSection42(): void
    {
        self::assertSame(
            ['super_admin', 'network_manager', 'store_manager', 'store_operator', 'analyst'],
            array_map(static fn (Role $r): string => $r->value, Role::staffRoles()),
        );

        foreach ([Role::SupplierAdmin, Role::SupplierOperator, Role::Driver] as $role) {
            self::assertSame(Contour::Partner, $role->contour());
        }
    }

    /**
     * RBAC-05: матриця версіонована — версія публікується для моніторингу.
     */
    public function testMatrixIsVersioned(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', PermissionMatrix::VERSION);
    }
}
