<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Вузька проєкція облікового запису для перевірки токена на КОЖНОМУ запиті
 * до API (ендпоїнт `/internal/v1/auth/verify`, який викликає api-gateway).
 *
 * Навмисно НЕ агрегат `StaffUser`: шлюзу потрібні лише роль, скоуп і ознака
 * активності, тому з БД читаються тільки ці поля. Хеш пароля та історія
 * останніх пʼяти хешів (AUTH-13) — сотні байтів на документ — по мережі
 * на кожен запит не їдуть і в памʼять сервісу не потрапляють (AUTH-61).
 */
final readonly class IdentitySnapshot
{
    /**
     * @param list<string> $storeIds
     * @param bool         $active    сукупна ознака: `active` І відсутність `archivedAt` (DATA-03)
     */
    public function __construct(
        public string $userId,
        public Role $role,
        public array $storeIds,
        public bool $active,
    ) {
    }

    public static function fromUser(StaffUser $user): self
    {
        return new self(
            userId: $user->id(),
            role: $user->role(),
            storeIds: $user->storeIds(),
            active: $user->isActive(),
        );
    }

    /**
     * RBAC-16: у ролей зі скоупом «вся мережа» перелік магазинів порожній —
     * їхній доступ визначається роллю, а не списком storeIds.
     *
     * @return list<string>
     */
    public function scopedStoreIds(): array
    {
        return $this->role->isNetworkWide() ? [] : $this->storeIds;
    }
}
