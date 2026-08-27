<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Канонічний перелік ролей системи (RBAC-06). Жодних інших ролей не існує.
 *
 * RBAC-04: користувач має рівно ОДНУ роль, тому заголовок X-User-Role несе
 * одне значення (клейм `role` в однині), а не перелік.
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

    /** RBAC-03: контур, якому належить роль. */
    public function contour(): Contour
    {
        return match ($this) {
            self::SuperAdmin, self::NetworkManager, self::StoreManager, self::StoreOperator, self::Analyst => Contour::Staff,
            self::SupplierAdmin, self::SupplierOperator, self::Driver => Contour::Partner,
        };
    }

    /**
     * RBAC-16: скоуп «вся мережа» — доступ до будь-якого магазину визначає
     * саме РОЛЬ, а не перелік магазинів (він у цих ролей завжди порожній).
     */
    public function isNetworkWide(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::NetworkManager, self::Analyst => true,
            default => false,
        };
    }

    /**
     * RBAC-13: магазинні ролі обмежені своїм переліком магазинів;
     * ПОРОЖНІЙ перелік означає нуль доступу, а не «усі магазини».
     */
    public function isStoreScoped(): bool
    {
        return match ($this) {
            self::StoreManager, self::StoreOperator => true,
            default => false,
        };
    }

    /** Користувач кабінету постачальника (supplier-web). */
    public function isSupplier(): bool
    {
        return match ($this) {
            self::SupplierAdmin, self::SupplierOperator => true,
            default => false,
        };
    }
}
