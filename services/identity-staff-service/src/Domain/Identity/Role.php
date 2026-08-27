<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Канонічний перелік ролей (RBAC-06). Жодних інших ролей не існує.
 *
 * RBAC-04: користувач має рівно ОДНУ роль у межах свого контуру; у токені
 * клейм `role` в однині. Множинність повноважень досягається скоупом.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case NetworkManager = 'network_manager';
    case StoreManager = 'store_manager';
    case StoreOperator = 'store_operator';
    case Analyst = 'analyst';
    case SupplierAdmin = 'supplier_admin';
    case SupplierOperator = 'supplier_operator';
    case Driver = 'driver';

    /**
     * RBAC-03: до якого контуру належить роль.
     */
    public function contour(): Contour
    {
        return match ($this) {
            self::SuperAdmin, self::NetworkManager, self::StoreManager, self::StoreOperator, self::Analyst => Contour::Staff,
            self::SupplierAdmin, self::SupplierOperator, self::Driver => Contour::Partner,
        };
    }

    /**
     * Ролі staff-контуру, які обслуговує identity-staff-service.
     *
     * @return list<self>
     */
    public static function staffRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => Contour::Staff === $role->contour(),
        ));
    }

    /**
     * RBAC-10: повний перелік прав ролі за матрицею 4.4.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return PermissionMatrix::permissionsOf($this);
    }

    /**
     * RBAC-10: тип надання права (✓ / S / P / —).
     */
    public function grantFor(Permission $permission): PermissionGrant
    {
        return PermissionMatrix::grant($this, $permission);
    }

    /**
     * RBAC-16: super_admin, network_manager і analyst мають скоуп «вся мережа»
     * без фільтрації за storeIds.
     */
    public function isNetworkWide(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::NetworkManager, self::Analyst => true,
            default => false,
        };
    }

    /**
     * RBAC-13: store_manager і store_operator обмежені масивом storeIds;
     * порожній масив = нуль доступу.
     */
    public function isStoreScoped(): bool
    {
        return match ($this) {
            self::StoreManager, self::StoreOperator => true,
            default => false,
        };
    }

    /**
     * RBAC-22: дерево призначення ролей.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::SuperAdmin => [
                self::SuperAdmin,
                self::NetworkManager,
                self::StoreManager,
                self::StoreOperator,
                self::Analyst,
                self::SupplierAdmin,
            ],
            self::NetworkManager => [
                self::StoreManager,
                self::StoreOperator,
                self::SupplierAdmin,
            ],
            self::SupplierAdmin => [
                self::SupplierOperator,
                self::Driver,
            ],
            default => [],
        };
    }

    public function canAssign(self $target): bool
    {
        return \in_array($target, $this->assignableRoles(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Суперадміністратор',
            self::NetworkManager => 'Менеджер мережі',
            self::StoreManager => 'Керівник магазину',
            self::StoreOperator => 'Приймальник магазину',
            self::Analyst => 'Аналітик',
            self::SupplierAdmin => 'Адміністратор постачальника',
            self::SupplierOperator => 'Оператор постачальника',
            self::Driver => 'Водій',
        };
    }
}
