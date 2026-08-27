<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Ідентичність запиту, зібрана зі службових заголовків шлюзу.
 *
 * Сам сервіс JWT не перевіряє: токен розбирають identity-сервіси
 * (GET /internal/v1/auth/verify), а nginx підставляє готові заголовки
 * X-User-Id / X-User-Role / X-Supplier-Id / X-Store-Ids / X-Contour.
 *
 * ДВА КЛЮЧОВІ ПРАВИЛА СКОУПУ, які цей клас закріплює:
 *
 *  1. Постачальник. Для supplier_admin і supplier_operator X-Supplier-Id
 *     обовʼязковий і непорожній. Порожній — ВІДМОВА, а не доступ до даних
 *     усіх постачальників.
 *  2. Магазини (RBAC-13). Для store_manager і store_operator перелік
 *     X-Store-Ids ВИЧЕРПНИЙ: порожній перелік означає нуль доступу, а не
 *     «будь-який магазин». Скоуп «уся мережа» дає РОЛЬ (super_admin,
 *     network_manager, analyst), і тільки вона (RBAC-16).
 */
final readonly class Actor
{
    /**
     * @param list<string> $storeIds магазини скоупу; для мережевих ролей порожній
     */
    public function __construct(
        public string $userId,
        public Role $role,
        public Contour $contour,
        public ?string $supplierId = null,
        public array $storeIds = [],
    ) {
        if ('' === $userId) {
            throw AccessDeniedException::missingIdentity();
        }

        if ($role->contour() !== $contour) {
            throw AccessDeniedException::contourMismatch();
        }

        if ($role->isSupplier() && (null === $supplierId || '' === $supplierId)) {
            throw AccessDeniedException::missingSupplier();
        }
    }

    public function grantFor(Permission $permission): PermissionGrant
    {
        return $permission->grantFor($this->role);
    }

    public function can(Permission $permission): bool
    {
        return $this->grantFor($permission)->isGranted();
    }

    /**
     * Мережевий доступ до адмін-API: право має бути надане повністю (✓).
     * Скоупного «S» тут замало — адмін-розділ працює поверх усіх постачальників.
     *
     * @throws AccessDeniedException
     */
    public function requireNetworkWide(Permission $permission): void
    {
        $grant = $this->grantFor($permission);

        if (PermissionGrant::Full === $grant) {
            return;
        }

        throw PermissionGrant::Scoped === $grant
            ? AccessDeniedException::networkScopeRequired($this->role, $permission)
            : AccessDeniedException::permissionDenied($this->role, $permission);
    }

    /**
     * Постачальник, у межах якого діє актор кабінету партнера.
     *
     * Ідентифікатор береться ВИКЛЮЧНО з ідентичності запиту, а не з URL чи
     * тіла, тому кабінет фізично не може звернутися до чужих даних. Право має
     * бути скоупним (S) — саме так матриця 4.4 описує роботу кабінету.
     *
     * @throws AccessDeniedException
     */
    public function requireOwnSupplierScope(Permission $permission): string
    {
        if (PermissionGrant::Scoped !== $this->grantFor($permission)) {
            throw AccessDeniedException::permissionDenied($this->role, $permission);
        }

        // Недосяжно завдяки інваріанту конструктора — лишається як fail-closed.
        if (null === $this->supplierId || '' === $this->supplierId) {
            throw AccessDeniedException::missingSupplier();
        }

        return $this->supplierId;
    }

    /** Чи діє актор від імені саме цього постачальника. */
    public function belongsToSupplier(string $supplierId): bool
    {
        return null !== $this->supplierId && $this->supplierId === $supplierId;
    }

    /**
     * Чи бачить актор дані конкретного магазину.
     *
     * Мережеві ролі — будь-який магазин; магазинні — рівно ті, що перелічені
     * в X-Store-Ids (порожній перелік = жодного, RBAC-13); ролі партнерського
     * контуру магазинного скоупу не мають узагалі.
     */
    public function canAccessStore(string $storeId): bool
    {
        if ($this->role->isNetworkWide()) {
            return true;
        }

        if (!$this->role->isStoreScoped()) {
            return false;
        }

        return \in_array($storeId, $this->storeIds, true);
    }

    /**
     * @throws AccessDeniedException
     */
    public function requireStoreAccess(string $storeId): void
    {
        if ($this->canAccessStore($storeId)) {
            return;
        }

        throw $this->role->isStoreScoped() && [] === $this->storeIds
            ? AccessDeniedException::emptyStoreScope()
            : AccessDeniedException::foreignStore($storeId);
    }
}
