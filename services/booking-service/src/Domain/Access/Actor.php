<?php

declare(strict_types=1);

namespace App\Domain\Access;

use InvalidArgumentException;

/**
 * Ініціатор доменної дії: користувач з рівно однією роллю або система (cron).
 *
 * Домен не знає нічого про JWT — токен розбирається в інфраструктурі,
 * сюди приходить уже готовий актор.
 */
final readonly class Actor
{
    public function __construct(
        public string $userId,
        public Role $role,
        /** Обовʼязковий для ролей контуру постачальника. */
        public ?string $supplierId = null,
        /** Магазин, до якого прикріплений співробітник магазину. */
        public ?string $storeId = null,
        /** true — дію виконує cron booking-service, а не людина (NOSH-01). */
        public bool $system = false,
    ) {
        if ('' === $userId) {
            throw new InvalidArgumentException('userId актора не може бути порожнім');
        }

        if ($role->isSupplier() && null === $supplierId) {
            throw new InvalidArgumentException('Для ролі постачальника обовʼязковий supplierId');
        }
    }

    /** Системний актор для планових завдань (авто-no_show, вивільнення резервів). */
    public static function system(): self
    {
        return new self(userId: 'system', role: Role::SuperAdmin, system: true);
    }

    /** Чи діє актор від імені саме цього постачальника. */
    public function belongsToSupplier(string $supplierId): bool
    {
        return $this->supplierId === $supplierId;
    }

    /** Чи має актор операційні повноваження магазину (свого або будь-якого для адміна). */
    public function canOperateStore(string $storeId): bool
    {
        if ($this->system || $this->role->isNetworkAdmin()) {
            return true;
        }

        return $this->role->isStoreStaff() && (null === $this->storeId || $this->storeId === $storeId);
    }
}
