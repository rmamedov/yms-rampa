<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Канонічні ролі системи. На користувача припадає рівно ОДНА роль —
 * заголовок X-User-Role несе одне значення, а не перелік.
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
     * RBAC-16: скоуп «уся мережа» визначається САМЕ роллю, а не переліком
     * магазинів. Для цих ролей X-Store-Ids приходить порожнім і це нормально —
     * фільтрації за магазинами немає взагалі.
     */
    public function isNetworkWide(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::NetworkManager, self::Analyst => true,
            default => false,
        };
    }

    /**
     * RBAC-13: store_manager і store_operator бачать РІВНО ті магазини, що
     * перелічені в X-Store-Ids. Порожній перелік = нуль доступу, а не «всі».
     */
    public function isStoreScoped(): bool
    {
        return match ($this) {
            self::StoreManager, self::StoreOperator => true,
            default => false,
        };
    }

    /** Роль контуру постачальника: для неї обовʼязковий X-Supplier-Id. */
    public function isSupplier(): bool
    {
        return match ($this) {
            self::SupplierAdmin, self::SupplierOperator => true,
            default => false,
        };
    }

    /** Контур, якому належить сама роль (звіряється з X-Contour). */
    public function contour(): Contour
    {
        return $this->isNetworkWide() || $this->isStoreScoped()
            ? Contour::Staff
            : Contour::Partner;
    }
}
