<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Канонічні ролі системи (RBAC-06). Жодних інших ролей не існує.
 *
 * RBAC-04: на користувача припадає рівно ОДНА роль — заголовок X-User-Role
 * несе одне значення, а не перелік.
 *
 * Не плутати з App\Domain\Identity\PartnerRole: та описує роль, яку
 * partner-service ПРИЗНАЧАЄ новому обліковому запису партнера, а ця —
 * роль того, ХТО прийшов із запитом (будь-якого з двох контурів).
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
     * магазинів. Для цих ролей X-Store-Ids приходить порожнім — і це норма:
     * фільтрації за магазинами для них немає взагалі.
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

    /** Роль кабінету постачальника: для неї обовʼязковий X-Supplier-Id. */
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
