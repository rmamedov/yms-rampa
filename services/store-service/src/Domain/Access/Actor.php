<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Shared\NotFoundException;

/**
 * Ініціатор запиту: користувач з рівно однією роллю (RBAC-04) і своїм скоупом.
 *
 * Сервіс не перевіряє JWT — це робить api-gateway через identity-сервіси, а
 * сюди ідентичність приходить у службових заголовках (див. ActorResolver).
 * Модель повторює контракт `GET /internal/v1/auth/verify` обох identity-сервісів
 * ОДИН-В-ОДИН: userId, role, supplierId, storeIds, contour.
 */
final readonly class Actor
{
    /**
     * @param list<string> $storeIds перелік магазинів у скоупі; порожній — або «не застосовно»
     *                               (мережеві та партнерські ролі), або НУЛЬ ДОСТУПУ (магазинні
     *                               ролі, RBAC-13). Розрізняє ці випадки роль, а не сам перелік
     */
    public function __construct(
        public string $userId,
        public Role $role,
        public ?string $supplierId,
        public array $storeIds,
        public Contour $contour,
    ) {
    }

    /**
     * RBAC-17: обовʼязковий предикат `storeId ∈ storeIds` для запиту в сховище.
     *
     * Повертає:
     *  - null — фільтрація за переліком НЕ застосовується:
     *      • мережеві ролі (RBAC-16) — доступ до будь-якого магазину дає роль;
     *      • ролі партнерського контуру, яким шлюз не передав переліку: їхній
     *        скоуп задає supplierId (RBAC-14), а X-Store-Ids для них порожній
     *        «бо не застосовно» — див. контракт identity-partner-service;
     *  - список — обовʼязковий предикат. Для магазинної ролі ПОРОЖНІЙ список
     *    означає нуль доступу (RBAC-13, RBAC-AC-08): вибірка гарантовано порожня.
     *    Порожній перелік НІКОЛИ не означає «усі магазини».
     *
     * @return list<string>|null
     */
    public function storeScope(): ?array
    {
        if ($this->role->isNetworkWide()) {
            return null;
        }

        if ($this->role->isStoreScoped()) {
            // Саме тут і живе правило RBAC-13: повертаємо перелік як є,
            // включно з порожнім — жодного «null = усі магазини».
            return $this->storeIds;
        }

        // Партнерський контур: перелік магазинів у скоуп не входить, але якщо
        // шлюз його все ж передав — він може лише ЗВУЗИТИ вибірку (SUP-03),
        // ніколи не розширити.
        return [] === $this->storeIds ? null : $this->storeIds;
    }

    /** Чи входить магазин у скоуп актора. */
    public function canAccessStore(string $storeId): bool
    {
        $scope = $this->storeScope();

        return null === $scope || \in_array($storeId, $scope, true);
    }

    /**
     * RBAC-18: читання одиничного магазину поза скоупом — 404, той самий
     * відгук, що й для неіснуючого магазину (існування не розкривається).
     */
    public function assertCanReadStore(string $storeId): void
    {
        if (!$this->canAccessStore($storeId)) {
            throw NotFoundException::store($storeId);
        }
    }

    /** RBAC-18: дія над магазином поза скоупом — 403 RBAC_SCOPE_VIOLATION. */
    public function assertCanActOnStore(string $storeId): void
    {
        if (!$this->canAccessStore($storeId)) {
            throw AccessDeniedException::storeOutOfScope($storeId);
        }
    }
}
