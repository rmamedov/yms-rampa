<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

/**
 * Результат створення постачальника: сам запис і — якщо адміністратор задав
 * логін — креденшли для входу в кабінет.
 *
 * Пароль повертається РІВНО ОДИН РАЗ (AUTH-24): у сховищі лежить лише хеш,
 * і показати його вдруге неможливо.
 */
final readonly class SupplierRegistration
{
    public function __construct(
        public Supplier $supplier,
        public ?string $login = null,
        public ?string $password = null,
    ) {
    }

    public function hasAccount(): bool
    {
        return null !== $this->login;
    }
}
