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
    /**
     * Перелік магазинів у скоупі співробітника магазину (RBAC-13).
     *
     * ПОРОЖНІЙ перелік означає НУЛЬ ДОСТУПУ, а не «усі магазини»: скоуп
     * «уся мережа» задається роллю (super_admin, network_manager), а не
     * відсутністю обмежень.
     *
     * @var list<string>
     */
    public array $storeIds;

    /**
     * @param list<string> $storeIds магазини у скоупі (заголовок X-Store-Ids)
     */
    public function __construct(
        public string $userId,
        public Role $role,
        /** Обовʼязковий для ролей контуру постачальника. */
        public ?string $supplierId = null,
        array $storeIds = [],
        /** true — дію виконує cron booking-service, а не людина (NOSH-01). */
        public bool $system = false,
    ) {
        if ('' === $userId) {
            throw new InvalidArgumentException('userId актора не може бути порожнім');
        }

        if ($role->isSupplier() && (null === $supplierId || '' === $supplierId)) {
            throw new InvalidArgumentException('Для ролі постачальника обовʼязковий supplierId');
        }

        $this->storeIds = self::normalizeStoreIds($storeIds);
    }

    /** Системний актор для планових завдань (авто-no_show, вивільнення резервів). */
    public static function system(): self
    {
        return new self(userId: 'system', role: Role::SuperAdmin, system: true);
    }

    /** Чи діє актор від імені саме цього постачальника. */
    public function belongsToSupplier(string $supplierId): bool
    {
        return null !== $this->supplierId && '' !== $this->supplierId && $this->supplierId === $supplierId;
    }

    /**
     * Чи має актор операційні повноваження магазину.
     *
     * RBAC-13: для store_manager / store_operator доступ обмежений переліком
     * магазинів зі скоупу; порожній перелік = жодного магазину. Мережеві ролі
     * (super_admin, network_manager) працюють у будь-якій філії — це дає РОЛЬ,
     * а не перелік магазинів.
     */
    public function canOperateStore(string $storeId): bool
    {
        if ($this->system || $this->role->isNetworkAdmin()) {
            return true;
        }

        if (!$this->role->isStoreStaff()) {
            return false;
        }

        return \in_array($storeId, $this->storeIds, true);
    }

    /**
     * @param list<string> $storeIds
     *
     * @return list<string>
     */
    private static function normalizeStoreIds(array $storeIds): array
    {
        $normalized = [];

        foreach ($storeIds as $storeId) {
            $storeId = trim($storeId);

            if ('' !== $storeId && !\in_array($storeId, $normalized, true)) {
                $normalized[] = $storeId;
            }
        }

        return $normalized;
    }
}
