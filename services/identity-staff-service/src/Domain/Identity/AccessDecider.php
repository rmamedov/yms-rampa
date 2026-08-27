<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Exception\PermissionDeniedException;
use App\Domain\Identity\Exception\ScopeViolationException;

/**
 * Другий рубіж авторизації (RBAC-20): перевірка права за матрицею 4.4
 * і скоупа за правилами 4.5. Довіра «gateway вже перевірив» заборонена.
 *
 * Правила, які реалізує клас:
 *  - RBAC-02: deny by default — усе, що не підтверджено матрицею, відхиляється;
 *  - RBAC-13: store_manager / store_operator обмежені своїм `storeIds`;
 *    ПОРОЖНІЙ масив = нуль доступу (а не вся мережа);
 *  - RBAC-16: super_admin / network_manager / analyst — скоуп «вся мережа»;
 *  - RBAC-18: для дії поза скоупом — 403 RBAC_SCOPE_VIOLATION, для читання
 *    одиничного ресурсу — 404 RESOURCE_NOT_FOUND (див. denyReadOrFail);
 *  - AUTH-12: деактивований акаунт не має жодного доступу.
 *
 * Клас не залежить від Symfony: Symfony-Voter-и мікросервісів (RBAC-20)
 * делегують рішення сюди.
 */
final readonly class AccessDecider
{
    /**
     * Коротка перевірка: чи дозволено дію.
     *
     * @param string|null $storeId магазин, якого стосується дія; null — перевірка «взагалі»
     *                             (наприклад, доступ до колекційного ендпоїнта)
     */
    public function can(StaffUser $user, Permission $permission, ?string $storeId = null): bool
    {
        return $this->decide($user, $permission, $storeId)->allowed;
    }

    /**
     * Повне рішення з кодом відмови для RFC 7807 та аудиту (RBAC-32).
     */
    public function decide(StaffUser $user, Permission $permission, ?string $storeId = null): AccessDecision
    {
        if (!$user->isActive()) {
            return AccessDecision::denyDisabled();
        }

        $grant = $user->role()->grantFor($permission);

        return match ($grant) {
            // — у матриці
            PermissionGrant::Denied => AccessDecision::denyPermission(),
            // ✓ — право в межах контуру, скоуп «вся мережа» (RBAC-16)
            PermissionGrant::Full => AccessDecision::allow($grant),
            // S — лише в межах скоупа (RBAC-13)
            PermissionGrant::Scoped => $this->decideScoped($user, $grant, $storeId),
            // P — лише публічні атрибути активних магазинів; для staff-ролей
            // у матриці 4.4 не зустрічається, тож дія відхиляється як відсутність права
            PermissionGrant::PublicOnly => AccessDecision::denyPermission(),
        };
    }

    /**
     * Імперативна перевірка для сервісного шару: кидає доменну помилку
     * з кодом за таблицею 4.10 замість повернення false.
     */
    public function assertCan(StaffUser $user, Permission $permission, ?string $storeId = null): void
    {
        $decision = $this->decide($user, $permission, $storeId);
        if ($decision->allowed) {
            return;
        }

        if ('RBAC_SCOPE_VIOLATION' === $decision->errorCode) {
            throw new ScopeViolationException($permission, $storeId);
        }

        throw new PermissionDeniedException($permission);
    }

    /**
     * RBAC-17: обовʼязковий предикат для запиту в MongoDB. Повертає:
     *  - null  — фільтр не потрібен (скоуп «вся мережа», RBAC-16);
     *  - список магазинів — обовʼязковий предикат `storeId ∈ storeIds`;
     *    порожній список означає нуль доступу, тобто гарантовано порожню вибірку
     *    (RBAC-AC-08), а не відсутність фільтра.
     *
     * @return list<string>|null
     */
    public function storeScopeFilter(StaffUser $user): ?array
    {
        return $user->isNetworkWide() ? null : $user->storeIds();
    }

    private function decideScoped(StaffUser $user, PermissionGrant $grant, ?string $storeId): AccessDecision
    {
        // RBAC-16: мережеві ролі не фільтруються за storeIds навіть для «S».
        if ($user->isNetworkWide()) {
            return AccessDecision::allow($grant);
        }

        // RBAC-13 / RBAC-AC-08: порожній storeIds — нуль доступу.
        if ([] === $user->storeIds()) {
            return AccessDecision::denyScope($grant);
        }

        // Перевірка «взагалі», без конкретного магазину: доступ є, якщо скоуп непорожній.
        if (null === $storeId) {
            return AccessDecision::allow($grant);
        }

        return $user->hasStoreInScope($storeId)
            ? AccessDecision::allow($grant)
            : AccessDecision::denyScope($grant);
    }
}
