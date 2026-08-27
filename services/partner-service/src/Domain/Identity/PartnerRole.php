<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Shared\ValidationException;

/**
 * Ролі партнерського контуру (розділ 10.6 `partner_accounts.role`).
 *
 * На користувача припадає РІВНО ОДНА роль; клейм токена — `role` (однина).
 * Staff-ролі (super_admin, network_manager, store_manager, store_operator,
 * analyst) живуть в іншому контурі й тут не використовуються.
 */
enum PartnerRole: string
{
    case SupplierAdmin = 'supplier_admin';
    case SupplierOperator = 'supplier_operator';
    case Driver = 'driver';

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? throw new ValidationException(
            \sprintf(
                'Невідома роль «%s». Допустимі: supplier_admin, supplier_operator, driver.',
                $value,
            ),
            'PARTNER_ROLE_INVALID',
        );
    }
}
