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
 * перевірені клейми в службових заголовках. Клейм ролі — `role` (однина):
 * на користувача припадає рівно одна роль.
 */
final readonly class ActorResolver
{
    public const string USER_HEADER = 'X-User-Id';
    public const string ROLE_HEADER = 'X-User-Role';
    public const string SUPPLIER_HEADER = 'X-Supplier-Id';
    public const string STORE_HEADER = 'X-Store-Id';

    public function fromRequest(Request $request): Actor
    {
        $userId = (string) $request->headers->get(self::USER_HEADER, '');
        $roleValue = (string) $request->headers->get(self::ROLE_HEADER, '');

        if ('' === $userId || '' === $roleValue) {
            throw new AccessDeniedException('Запит без ідентичності користувача');
        }

        $role = Role::tryFrom($roleValue);

        if (null === $role) {
            throw new AccessDeniedException(\sprintf('Невідома роль «%s»', $roleValue));
        }

        $supplierId = $request->headers->get(self::SUPPLIER_HEADER);
        $storeId = $request->headers->get(self::STORE_HEADER);

        if ($role->isSupplier() && (null === $supplierId || '' === $supplierId)) {
            throw new AccessDeniedException('Для ролі постачальника обовʼязковий заголовок '.self::SUPPLIER_HEADER);
        }

        return new Actor(
            userId: $userId,
            role: $role,
            supplierId: '' === $supplierId ? null : $supplierId,
            storeId: '' === $storeId ? null : $storeId,
        );
    }
}
