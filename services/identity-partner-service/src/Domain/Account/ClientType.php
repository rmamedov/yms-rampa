<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Клієнтський застосунок партнерського контуру.
 *
 * Від клієнта залежить тривалість refresh-токена (AUTH-27, DRV-07, розділ 3.4):
 *  - supplier-web — 30 днів;
 *  - driver-web   — 90 днів із прапорцем «Запамʼятати мене» (за замовчуванням
 *    увімкнений) і 30 днів без нього.
 */
enum ClientType: string
{
    case SupplierWeb = 'supplier-web';
    case DriverWeb = 'driver-web';

    /**
     * Ролі, яким дозволено входити через цей застосунок.
     *
     * DRV-10: у driver-web пускаємо лише роль driver; постачальник входить
     * у supplier-web.
     *
     * @return list<PartnerRole>
     */
    public function allowedRoles(): array
    {
        return match ($this) {
            self::SupplierWeb => [PartnerRole::SupplierAdmin, PartnerRole::SupplierOperator],
            self::DriverWeb => [PartnerRole::Driver],
        };
    }

    public function allowsRole(PartnerRole $role): bool
    {
        return \in_array($role, $this->allowedRoles(), true);
    }

    /** Логін цього застосунку — телефон (driver-web) чи email (supplier-web). */
    public function loginIsPhone(): bool
    {
        return self::DriverWeb === $this;
    }

    /** Тривалість refresh-токена в днях (AUTH-27, DRV-07). */
    public function refreshTtlDays(bool $rememberMe = true): int
    {
        return match ($this) {
            self::SupplierWeb => 30,
            self::DriverWeb => $rememberMe ? 90 : 30,
        };
    }

    public function refreshTtlSeconds(bool $rememberMe = true): int
    {
        return $this->refreshTtlDays($rememberMe) * 86400;
    }
}
