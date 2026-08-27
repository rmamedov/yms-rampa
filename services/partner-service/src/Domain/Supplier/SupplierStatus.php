<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

use App\Domain\Shared\ValidationException;

/**
 * Статус постачальника (SUP-01, SUP-02).
 *
 * `suspended` — користувачі постачальника і його водії не проходять
 * автентифікацію в identity-partner-service, але чинні бронювання
 * зберігаються і залишаються видимими магазинам.
 */
enum SupplierStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? throw new ValidationException(
            \sprintf('Невідомий статус постачальника «%s». Допустимі: active, suspended.', $value),
            'SUPPLIER_STATUS_INVALID',
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активний',
            self::Suspended => 'Призупинений',
        };
    }
}
