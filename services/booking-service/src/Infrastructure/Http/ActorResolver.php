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
 *   X-User-Id     — ідентифікатор користувача;
 *   X-User-Role   — рівно ОДНА роль (клейм `role`, однина);
 *   X-Supplier-Id — постачальник або порожній рядок;
 *   X-Store-Ids   — перелік магазинів у скоупі через кому без пробілів
 *                   («S-01,S-02») або порожній рядок;
 *   X-Contour     — staff | partner (обчислюється з ролі, тут не потрібен).
 *
 * RBAC-13: порожній X-Store-Ids для магазинних ролей означає НУЛЬ ДОСТУПУ,
 * а не «усі магазини» — див. Actor::canOperateStore().
 */
final readonly class ActorResolver
{
    public const string USER_HEADER = 'X-User-Id';
    public const string ROLE_HEADER = 'X-User-Role';
    public const string SUPPLIER_HEADER = 'X-Supplier-Id';
    public const string STORES_HEADER = 'X-Store-Ids';

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
}
