<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use Symfony\Component\HttpFoundation\Request;

/**
 * Розбір ідентичності запиту.
 *
 * Валідацію JWT виконує API Gateway / identity-сервіси, сюди приходять уже
 * перевірені клейми в службових заголовках єдиного контракту ідентичності:
 *
 *   X-User-Id           — ідентифікатор ОБЛІКОВОГО ЗАПИСУ (клейм `sub`);
 *   X-User-Role         — рівно ОДНА роль (клейм `role`, однина);
 *   X-Supplier-Id       — постачальник або порожній рядок;
 *   X-Store-Ids         — перелік магазинів у скоупі через кому без пробілів
 *                         («S-01,S-02») або порожній рядок;
 *   X-Driver-Profile-Id — ПРОФІЛЬ водія (partner_users) або порожній рядок;
 *   X-Contour           — staff | partner (обчислюється з ролі, тут не потрібен).
 *
 * DRV: X-User-Id і X-Driver-Profile-Id — РІЗНІ ідентифікатори.
 * identity-partner-service створює обліковий запис (partner_accounts) і саме
 * його кладе в `sub`, тоді як бронювання зберігає driverId профілю
 * (partner_users), створеного partner-service. Звʼязок тримає
 * partner_accounts.driverProfileId, і шлюз підставляє профіль окремим
 * заголовком — примусово, як і решту.
 *
 * RBAC-13: порожній X-Store-Ids для магазинних ролей означає НУЛЬ ДОСТУПУ,
 * а не «усі магазини» — див. Actor::canOperateStore().
 * Так само порожній X-Driver-Profile-Id для водія означає НУЛЬ ДОСТУПУ
 * до контуру водія — див. Actor::canActOnOwnRouteSheet().
 */
final readonly class ActorResolver
{
    public const string USER_HEADER = 'X-User-Id';
    public const string ROLE_HEADER = 'X-User-Role';
    public const string SUPPLIER_HEADER = 'X-Supplier-Id';
    public const string STORES_HEADER = 'X-Store-Ids';
    public const string DRIVER_PROFILE_HEADER = 'X-Driver-Profile-Id';

    public function fromRequest(Request $request): Actor
    {
        $userId = trim((string) $request->headers->get(self::USER_HEADER, ''));
        $roleValue = trim((string) $request->headers->get(self::ROLE_HEADER, ''));

        if ('' === $userId || '' === $roleValue) {
            throw new AccessDeniedException('Запит без ідентичності користувача');
        }

        $role = Role::tryFrom($roleValue);

        if (null === $role) {
            throw new AccessDeniedException(\sprintf('Невідома роль «%s»', $roleValue));
        }

        $supplierId = trim((string) $request->headers->get(self::SUPPLIER_HEADER, ''));

        if ($role->isSupplier() && '' === $supplierId) {
            throw new AccessDeniedException('Для ролі постачальника обовʼязковий заголовок '.self::SUPPLIER_HEADER);
        }

        return new Actor(
            userId: $userId,
            role: $role,
            supplierId: '' === $supplierId ? null : $supplierId,
            storeIds: self::parseStoreIds((string) $request->headers->get(self::STORES_HEADER, '')),
            driverProfileId: self::parseDriverProfileId($role, (string) $request->headers->get(self::DRIVER_PROFILE_HEADER, '')),
        );
    }

    /**
     * «S-01,S-02» → ['S-01', 'S-02']; порожній рядок → [] (нуль магазинів).
     *
     * @return list<string>
     */
    private static function parseStoreIds(string $header): array
    {
        if ('' === trim($header)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $header)),
            static fn (string $storeId) => '' !== $storeId,
        ));
    }

    /**
     * Профіль має значення ВИКЛЮЧНО для ролі `driver`: для решти ролей
     * заголовок ігнорується і жодних повноважень не додає — їхні контури
     * тримають canOperateStore() і belongsToSupplier().
     *
     * Порожнє значення для водія → null: акаунт без привʼязаного профілю
     * не діє в контурі водія взагалі.
     */
    private static function parseDriverProfileId(Role $role, string $header): ?string
    {
        if (Role::Driver !== $role) {
            return null;
        }

        $driverProfileId = trim($header);

        return '' === $driverProfileId ? null : $driverProfileId;
    }
}
