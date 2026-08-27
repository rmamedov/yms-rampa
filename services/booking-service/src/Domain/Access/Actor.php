<?php

declare(strict_types=1);

namespace App\Domain\Access;

use InvalidArgumentException;

/**
 * Ініціатор доменної дії: користувач з рівно однією роллю або система (cron).
 *
 * Домен не знає нічого про JWT — токен розбирається в інфраструктурі,
 * сюди приходить уже готовий актор.
 *
 * DRV: у контурі партнера ідентичностей ДВІ, і вони не збігаються:
 *   $userId          — ОБЛІКОВИЙ ЗАПИС (partner_accounts), клейм `sub` токена;
 *   $driverProfileId — ПРОФІЛЬ водія (partner_users), бізнес-ідентичність.
 * Бронювання зберігає driverId саме профілю, тому належність точки водієві
 * визначає ПРОФІЛЬ, а не обліковий запис.
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
     * Профіль водія зі заголовка X-Driver-Profile-Id (partner_users).
     *
     * null означає «водій без привʼязаного профілю» = НУЛЬ ДОСТУПУ до дій
     * контуру водія, а не «підходить будь-яке бронювання». Запасного
     * порівняння з $userId НЕМАЄ і бути не може: обліковий запис і профіль —
     * різні ідентифікатори, їхній збіг був би випадковістю.
     */
    public ?string $driverProfileId;

    /**
     * @param list<string> $storeIds магазини у скоупі (заголовок X-Store-Ids)
     */
    public function __construct(
        public string $userId,
        public Role $role,
        /** Обовʼязковий для ролей контуру постачальника. */
        public ?string $supplierId = null,
        array $storeIds = [],
        /** Профіль водія (заголовок X-Driver-Profile-Id); порожнє значення = null. */
        ?string $driverProfileId = null,
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
        $this->driverProfileId = self::normalizeDriverProfileId($driverProfileId);
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
     * Водій із привʼязаним профілем — ЄДИНА форма актора, яка щось може
     * в контурі водія. Роль `driver` без профілю не діє взагалі.
     */
    public function hasDriverProfile(): bool
    {
        return Role::Driver === $this->role && null !== $this->driverProfileId;
    }

    /**
     * DRV: чи діє водій щодо ВЛАСНОЇ точки маршрутного листа.
     *
     * Єдина підстава повноважень водія — бронювання закріплене саме за його
     * ПРОФІЛЕМ (`booking.driverId === driverProfileId`); призначення водія
     * в маршрутному листі і в бронюванні тримає синхронними RouteSheetService.
     *
     * Перевірка НАВМИСНО вузька і нічого не розширює:
     *  - інші ролі (магазин, адмін мережі, постачальник) отримують false —
     *    їхні повноваження дає canOperateStore()/belongsToSupplier();
     *  - водій без профілю (порожній X-Driver-Profile-Id) отримує false —
     *    порівняння з обліковим записом $userId НЕ виконується;
     *  - бронювання без водія (`driverId = null`) не належить нікому.
     */
    public function canActOnOwnRouteSheet(?string $driverId): bool
    {
        if (!$this->hasDriverProfile()) {
            return false;
        }

        return null !== $driverId && '' !== trim($driverId) && $driverId === $this->driverProfileId;
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
     * Чи має актор операційні повноваження магазину ХОЧ ДЕСЬ.
     *
     * Питання має сенс лише для маршрутів без конкретної філії — насамперед
     * для переліку доступних магазинів. Мережева роль проходить за роллю,
     * магазинна — за належністю до контуру магазину; порожній скоуп її НЕ
     * відсіює тут, бо це не «немає прав», а «прав рівно на нуль магазинів»:
     * відповіддю буде порожній перелік, а не 403.
     */
    public function canOperateAnyStore(): bool
    {
        return $this->system || $this->role->isNetworkAdmin() || $this->role->isStoreStaff();
    }

    /**
     * Єдина точка перевірки доступу до філії для контуру магазину — і для
     * дій, і для читання. Тримати їх на різних правилах не можна: інакше
     * зʼявиться екран, який показує те, чого не можна змінити, або навпаки.
     *
     * @throws AccessDeniedException якщо філія поза скоупом актора
     */
    public function assertCanOperateStore(string $storeId): void
    {
        if (!$this->canOperateStore($storeId)) {
            throw AccessDeniedException::foreignStore($storeId);
        }
    }

    /**
     * @throws AccessDeniedException якщо роль узагалі не належить контуру магазину
     */
    public function assertCanOperateAnyStore(): void
    {
        if (!$this->canOperateAnyStore()) {
            throw AccessDeniedException::storeContourOnly();
        }
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

    /** Порожній рядок і самі пробіли — це «профілю немає», тобто null. */
    private static function normalizeDriverProfileId(?string $driverProfileId): ?string
    {
        if (null === $driverProfileId || '' === trim($driverProfileId)) {
            return null;
        }

        return trim($driverProfileId);
    }
}
