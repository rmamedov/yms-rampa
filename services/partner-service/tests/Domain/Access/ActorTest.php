<?php

declare(strict_types=1);

namespace App\Tests\Domain\Access;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Contour;
use App\Domain\Access\Permission;
use App\Domain\Access\PermissionGrant;
use App\Domain\Access\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Правила скоупу актора: постачальник (X-Supplier-Id) і магазини
 * (X-Store-Ids) — закріплені зокрема негативними випадками.
 */
final class ActorTest extends TestCase
{
    /**
     * КЛЮЧОВЕ ПРАВИЛО (RBAC-13): порожній перелік магазинів для магазинної
     * ролі — це НУЛЬ доступу, а не «будь-який магазин».
     */
    #[DataProvider('storeScopedRoles')]
    public function testStoreRoleWithEmptyScopeReachesNoStoreAtAll(Role $role): void
    {
        $actor = self::staff($role, storeIds: []);

        foreach (['S-01', 'S-02', 'S-999'] as $storeId) {
            self::assertFalse($actor->canAccessStore($storeId), $storeId);
        }
    }

    #[DataProvider('storeScopedRoles')]
    public function testEmptyStoreScopeIsRefusedExplicitly(Role $role): void
    {
        $this->expectException(AccessDeniedException::class);

        self::staff($role, storeIds: [])->requireStoreAccess('S-01');
    }

    /**
     * Перелік [A, B] означає рівно A і B — і нічого поза ним.
     */
    #[DataProvider('storeScopedRoles')]
    public function testStoreRoleSeesOnlyListedStores(Role $role): void
    {
        $actor = self::staff($role, storeIds: ['S-01', 'S-02']);

        self::assertTrue($actor->canAccessStore('S-01'));
        self::assertTrue($actor->canAccessStore('S-02'));
        self::assertFalse($actor->canAccessStore('S-03'));
    }

    #[DataProvider('storeScopedRoles')]
    public function testForeignStoreIsRefused(Role $role): void
    {
        $this->expectException(AccessDeniedException::class);

        self::staff($role, storeIds: ['S-01', 'S-02'])->requireStoreAccess('S-03');
    }

    /**
     * RBAC-16: скоуп «уся мережа» дає роль, а не перелік магазинів —
     * тому мережева роль із порожнім X-Store-Ids бачить будь-який магазин.
     */
    #[DataProvider('networkWideRoles')]
    public function testNetworkRoleReachesAnyStoreWithEmptyStoreList(Role $role): void
    {
        $actor = self::staff($role, storeIds: []);

        foreach (['S-01', 'S-77', 'S-999'] as $storeId) {
            self::assertTrue($actor->canAccessStore($storeId), $storeId);
        }

        $actor->requireStoreAccess('S-01');
        $this->addToAssertionCount(1);
    }

    /**
     * Ролі партнерського контуру магазинного скоупу не мають узагалі.
     */
    public function testPartnerRolesHaveNoStoreScope(): void
    {
        $supplier = self::partner(Role::SupplierAdmin, 'sp-1');

        self::assertFalse($supplier->canAccessStore('S-01'));
        self::assertFalse(self::partner(Role::Driver, 'sp-1')->canAccessStore('S-01'));
    }

    /**
     * КЛЮЧОВЕ ПРАВИЛО: порожній X-Supplier-Id для ролі постачальника —
     * відмова, а не доступ до всіх постачальників.
     */
    #[DataProvider('supplierRoles')]
    public function testSupplierRoleWithoutSupplierIdIsRefused(Role $role): void
    {
        $this->expectException(AccessDeniedException::class);

        new Actor(userId: 'u-1', role: $role, contour: Contour::Partner, supplierId: null);
    }

    #[DataProvider('supplierRoles')]
    public function testSupplierRoleWithEmptySupplierIdIsRefused(Role $role): void
    {
        $this->expectException(AccessDeniedException::class);

        new Actor(userId: 'u-1', role: $role, contour: Contour::Partner, supplierId: '');
    }

    public function testSupplierActorIsBoundToItsOwnSupplier(): void
    {
        $actor = self::partner(Role::SupplierAdmin, 'sp-1');

        self::assertTrue($actor->belongsToSupplier('sp-1'));
        self::assertFalse($actor->belongsToSupplier('sp-2'));
        self::assertSame('sp-1', $actor->requireOwnSupplierScope(Permission::VehicleManage));
    }

    /**
     * Скоупний доступ до кабінету не перетворюється на мережевий:
     * supplier_admin читає СВОГО постачальника, але не довідник цілком.
     */
    public function testScopedSupplierReadIsNotNetworkWide(): void
    {
        $actor = self::partner(Role::SupplierAdmin, 'sp-1');

        self::assertSame(PermissionGrant::Scoped, $actor->grantFor(Permission::SupplierRead));

        $this->expectException(AccessDeniedException::class);
        $actor->requireNetworkWide(Permission::SupplierRead);
    }

    public function testContourHeaderMustMatchRoleContour(): void
    {
        $this->expectException(AccessDeniedException::class);

        new Actor(userId: 'u-1', role: Role::SupplierAdmin, contour: Contour::Staff, supplierId: 'sp-1');
    }

    public function testEmptyUserIdIsRefused(): void
    {
        $this->expectException(AccessDeniedException::class);

        new Actor(userId: '', role: Role::SuperAdmin, contour: Contour::Staff);
    }

    /**
     * Матриця 4.4 (RBAC-10) для рядків, які перевіряє цей сервіс.
     *
     * @param array<string, string> $expected роль → символ матриці
     */
    #[DataProvider('matrixRows')]
    public function testPermissionMatrixMatchesTable44(Permission $permission, array $expected): void
    {
        foreach ($expected as $roleValue => $symbol) {
            self::assertSame(
                $symbol,
                $permission->grantFor(Role::from($roleValue))->value,
                $permission->value.' × '.$roleValue,
            );
        }
    }

    /**
     * @return iterable<string, array{Permission, array<string, string>}>
     */
    public static function matrixRows(): iterable
    {
        yield 'supplier.read' => [Permission::SupplierRead, [
            'super_admin' => '✓',
            'network_manager' => '✓',
            'store_manager' => '—',
            'store_operator' => '—',
            'analyst' => '✓',
            'supplier_admin' => 'S',
            'supplier_operator' => '—',
            'driver' => '—',
        ]];

        yield 'supplier.manage' => [Permission::SupplierManage, [
            'super_admin' => '✓',
            'network_manager' => '✓',
            'store_manager' => '—',
            'store_operator' => '—',
            'analyst' => '—',
            'supplier_admin' => '—',
            'supplier_operator' => '—',
            'driver' => '—',
        ]];

        yield 'driver.manage' => [Permission::DriverManage, [
            'super_admin' => '—',
            'network_manager' => '—',
            'store_manager' => '—',
            'store_operator' => '—',
            'analyst' => '—',
            'supplier_admin' => 'S',
            'supplier_operator' => '—',
            'driver' => '—',
        ]];

        yield 'vehicle.manage' => [Permission::VehicleManage, [
            'super_admin' => '—',
            'network_manager' => '—',
            'store_manager' => '—',
            'store_operator' => '—',
            'analyst' => '—',
            'supplier_admin' => 'S',
            'supplier_operator' => 'S',
            'driver' => '—',
        ]];
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function storeScopedRoles(): iterable
    {
        yield 'store_manager' => [Role::StoreManager];
        yield 'store_operator' => [Role::StoreOperator];
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function networkWideRoles(): iterable
    {
        yield 'super_admin' => [Role::SuperAdmin];
        yield 'network_manager' => [Role::NetworkManager];
        yield 'analyst' => [Role::Analyst];
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function supplierRoles(): iterable
    {
        yield 'supplier_admin' => [Role::SupplierAdmin];
        yield 'supplier_operator' => [Role::SupplierOperator];
    }

    /**
     * @param list<string> $storeIds
     */
    private static function staff(Role $role, array $storeIds): Actor
    {
        return new Actor(
            userId: 'u-'.$role->value,
            role: $role,
            contour: Contour::Staff,
            storeIds: $storeIds,
        );
    }

    private static function partner(Role $role, string $supplierId): Actor
    {
        return new Actor(
            userId: 'u-'.$role->value,
            role: $role,
            contour: Contour::Partner,
            supplierId: $supplierId,
        );
    }
}
