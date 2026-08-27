<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Результат перевірки доступу з машинним кодом відмови за таблицею 4.10.
 *
 * RBAC-32: кожна відмова логується структурою userId / permission / resource /
 * errorCode / requestId, тому рішення несе код, а не лише булеве значення.
 */
final readonly class AccessDecision
{
    private function __construct(
        public bool $allowed,
        public PermissionGrant $grant,
        public ?string $errorCode,
        public ?string $reason,
    ) {
    }

    public static function allow(PermissionGrant $grant): self
    {
        return new self(true, $grant, null, null);
    }

    /**
     * Таблиця 4.10, сценарій 3: роль не має права за матрицею 4.4.
     */
    public static function denyPermission(): self
    {
        return new self(false, PermissionGrant::Denied, 'RBAC_PERMISSION_DENIED', 'У вас немає прав для цієї дії');
    }

    /**
     * Таблиця 4.10, сценарій 4 / RBAC-18: право є, але ресурс поза скоупом.
     */
    public static function denyScope(PermissionGrant $grant): self
    {
        return new self(false, $grant, 'RBAC_SCOPE_VIOLATION', 'Дія недоступна для цього магазину');
    }

    /**
     * AUTH-12: деактивований обліковий запис не має доступу взагалі.
     */
    public static function denyDisabled(): self
    {
        return new self(false, PermissionGrant::Denied, 'AUTH_ACCOUNT_DISABLED', 'Обліковий запис деактивовано. Зверніться до адміністратора.');
    }
}
