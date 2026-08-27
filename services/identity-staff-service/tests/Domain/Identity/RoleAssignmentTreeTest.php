<?php

declare(strict_types=1);

namespace App\Tests\Domain\Identity;

use App\Domain\Identity\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Дерево призначення ролей 4.7 (RBAC-22, RBAC-23).
 */
#[CoversClass(Role::class)]
final class RoleAssignmentTreeTest extends TestCase
{
    /**
     * @return array<string, array{Role, Role, bool}>
     */
    public static function assignmentProvider(): array
    {
        return [
            // super_admin — усі staff-ролі та supplier_admin
            'super_admin → super_admin' => [Role::SuperAdmin, Role::SuperAdmin, true],
            'super_admin → network_manager' => [Role::SuperAdmin, Role::NetworkManager, true],
            'super_admin → store_manager' => [Role::SuperAdmin, Role::StoreManager, true],
            'super_admin → store_operator' => [Role::SuperAdmin, Role::StoreOperator, true],
            'super_admin → analyst' => [Role::SuperAdmin, Role::Analyst, true],
            'super_admin → supplier_admin' => [Role::SuperAdmin, Role::SupplierAdmin, true],
            'super_admin → driver (лише постачальник)' => [Role::SuperAdmin, Role::Driver, false],

            // network_manager — тільки store_manager, store_operator і supplier_admin
            'network_manager → store_manager' => [Role::NetworkManager, Role::StoreManager, true],
            'network_manager → store_operator' => [Role::NetworkManager, Role::StoreOperator, true],
            'network_manager → supplier_admin' => [Role::NetworkManager, Role::SupplierAdmin, true],
            'network_manager → analyst (поза деревом)' => [Role::NetworkManager, Role::Analyst, false],
            'network_manager → super_admin (поза деревом)' => [Role::NetworkManager, Role::SuperAdmin, false],
            'network_manager → network_manager' => [Role::NetworkManager, Role::NetworkManager, false],

            // supplier_admin — лише свій контур
            'supplier_admin → supplier_operator' => [Role::SupplierAdmin, Role::SupplierOperator, true],
            'supplier_admin → driver' => [Role::SupplierAdmin, Role::Driver, true],
            'supplier_admin → store_manager' => [Role::SupplierAdmin, Role::StoreManager, false],

            // решта ролей нікого не призначають
            'store_manager → store_operator' => [Role::StoreManager, Role::StoreOperator, false],
            'analyst → analyst' => [Role::Analyst, Role::Analyst, false],
            'driver → driver' => [Role::Driver, Role::Driver, false],
        ];
    }

    #[DataProvider('assignmentProvider')]
    public function testAssignmentTree(Role $actor, Role $target, bool $expected): void
    {
        self::assertSame($expected, $actor->canAssign($target));
    }

    /**
     * RBAC-16: скоуп «вся мережа» визначається саме роллю.
     */
    public function testNetworkWideRoles(): void
    {
        self::assertTrue(Role::SuperAdmin->isNetworkWide());
        self::assertTrue(Role::NetworkManager->isNetworkWide());
        self::assertTrue(Role::Analyst->isNetworkWide());

        self::assertFalse(Role::StoreManager->isNetworkWide());
        self::assertFalse(Role::StoreOperator->isNetworkWide());

        self::assertTrue(Role::StoreManager->isStoreScoped());
        self::assertTrue(Role::StoreOperator->isStoreScoped());
        self::assertFalse(Role::Analyst->isStoreScoped());
    }
}
