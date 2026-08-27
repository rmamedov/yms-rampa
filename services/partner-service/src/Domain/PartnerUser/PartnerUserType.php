<?php

declare(strict_types=1);

namespace App\Domain\PartnerUser;

use App\Domain\Shared\ValidationException;

/**
 * Тип бізнес-профілю партнерського контуру (розділ 10.4 `partner_users.type`).
 *
 * `supplier` — користувач кабінету постачальника (ролі supplier_admin /
 * supplier_operator), логін — e-mail; `driver` — водій, логін — телефон.
 */
enum PartnerUserType: string
{
    case Supplier = 'supplier';
    case Driver = 'driver';

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? throw new ValidationException(
            \sprintf('Невідомий тип користувача «%s». Допустимі: supplier, driver.', $value),
            'PARTNER_USER_TYPE_INVALID',
        );
    }
}
