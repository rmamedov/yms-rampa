<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Канонічні ролі системи. На користувача припадає рівно ОДНА роль,
 * клейм токена — `role` (однина).
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

    /** Співробітник магазину: веде операційні переходи статусів. */
    public function isStoreStaff(): bool
    {
        return self::StoreManager === $this || self::StoreOperator === $this;
    }

    /** Адміністратор мережі: має повноваження магазину в будь-якій філії. */
    public function isNetworkAdmin(): bool
    {
        return self::SuperAdmin === $this || self::NetworkManager === $this;
    }

    /** Користувач кабінету постачальника. */
    public function isSupplier(): bool
    {
        return self::SupplierAdmin === $this || self::SupplierOperator === $this;
    }

    /** Контур staff — admin + store. */
    public function isStaff(): bool
    {
        return $this->isStoreStaff() || $this->isNetworkAdmin() || self::Analyst === $this;
    }

    /** Контур partner — supplier + driver. */
    public function isPartner(): bool
    {
        return $this->isSupplier() || self::Driver === $this;
    }

    public function contour(): Contour
    {
        return $this->isPartner() ? Contour::Partner : Contour::Staff;
    }

    /**
     * Людиночитана назва ролі — та сама, що в довіднику ролей
     * identity-staff-service і в шапці інтерфейсів.
     *
     * Потрібна журналу дій бронювання (DATA-14): без неї колонка «Хто»
     * показує голий ідентифікатор облікового запису.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Адміністратор системи',
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
