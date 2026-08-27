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
 * КЛЮЧОВЕ ПРАВИЛО СКОУПУ (RBAC-13). Для store_manager і store_operator
 * перелік магазинів — ВИЧЕРПНИЙ: порожній перелік означає нуль доступу,
 * а не «будь-який магазин». Скоуп «уся мережа» дає РОЛЬ
 * (super_admin, network_manager, analyst), і тільки вона.
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
            throw new \InvalidArgumentException('userId актора не може бути порожнім');
        }

        if ($role->isSupplier() && (null === $supplierId || '' === $supplierId)) {
            throw new \InvalidArgumentException('Для ролі постачальника обовʼязковий supplierId');
        }
    }

    /** Дашборди аналітики доступні лише ролям staff-контуру. */
    public function canReadAnalytics(): bool
    {
        return Contour::Staff === $this->contour && Contour::Staff === $this->role->contour();
    }

    /**
     * Чи бачить актор дані конкретного магазину.
     *
     * Мережеві ролі — будь-який магазин; магазинні — лише перелічені в
     * X-Store-Ids (порожній перелік = жодного); решта ролей — жодного.
     */
    public function canAccessStore(string $storeId): bool
    {
        if (!$this->canReadAnalytics()) {
            return false;
        }

        if ($this->role->isNetworkWide()) {
            return true;
        }

        return in_array($storeId, $this->storeIds, true);
    }

    /**
     * Звужує запитані магазини до скоупу актора — результат іде у фільтр
     * AnalyticsQuery::$storeIds.
     *
     * Для мережевих ролей повертається запитане як є (порожнє = вся мережа).
     * Для магазинних роль гарантує НЕПОРОЖНІЙ перелік: порожній скоуп і
     * запит чужого магазину — це відмова, а не тихе розширення вибірки.
     *
     * @param list<string> $requested магазини з фільтра ?storeId=...
     *
     * @return list<string>
     *
     * @throws AccessDeniedException
     */
    public function narrowStoreScope(array $requested): array
    {
        if (!$this->canReadAnalytics()) {
            throw AccessDeniedException::analyticsIsStaffOnly($this->role);
        }

        if ($this->role->isNetworkWide()) {
            return $requested;
        }

        // RBAC-13: магазинна роль без магазинів не бачить нічого.
        if ([] === $this->storeIds) {
            throw AccessDeniedException::emptyStoreScope();
        }

        if ([] === $requested) {
            return $this->storeIds;
        }

        foreach ($requested as $storeId) {
            if (!in_array($storeId, $this->storeIds, true)) {
                throw AccessDeniedException::foreignStore($storeId);
            }
        }

        return $requested;
    }
}
