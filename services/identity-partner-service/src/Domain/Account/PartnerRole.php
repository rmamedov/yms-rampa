<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Ролі партнерського контуру (AUTH-29, DATA-35).
 *
 * У користувача рівно ОДНА роль; у токені — клейм `role` в однині (AUTH-11).
 * Ролі staff-контуру (super_admin, network_manager, store_manager,
 * store_operator, analyst) у цьому сервісі не існують взагалі.
 */
enum PartnerRole: string
{
    case SupplierAdmin = 'supplier_admin';
    case SupplierOperator = 'supplier_operator';
    case Driver = 'driver';

    public function isDriver(): bool
    {
        return self::Driver === $this;
    }

    public function isSupplierSide(): bool
    {
        return self::SupplierAdmin === $this || self::SupplierOperator === $this;
    }

    /** Тип користувача, що зберігається в refresh_tokens.userType (10.6). */
    public function userType(): UserType
    {
        return $this->isDriver() ? UserType::Driver : UserType::Supplier;
    }

    /** Логін водія — телефон E.164, логін постачальника — email (AUTH-23, AUTH-29). */
    public function loginIsPhone(): bool
    {
        return $this->isDriver();
    }
}
