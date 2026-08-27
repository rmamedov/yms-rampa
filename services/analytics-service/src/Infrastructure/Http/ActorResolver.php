<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Contour;
use App\Domain\Access\Role;
use Symfony\Component\HttpFoundation\Request;

/**
 * Розбір службових заголовків ідентичності — єдиний контракт, який віддають
 * identity-staff-service та identity-partner-service на
 * GET /internal/v1/auth/verify, а nginx підставляє в кожен запит до /api/:
 *
 *   X-User-Id     — ідентифікатор користувача;
 *   X-User-Role   — рівно ОДНА роль;
 *   X-Supplier-Id — постачальник; порожній рядок, якщо не застосовно;
 *   X-Store-Ids   — магазини скоупу через кому без пробілів; порожній рядок,
 *                   якщо не застосовно (для мережевих ролей — завжди);
 *   X-Contour     — staff | partner.
 *
 * Заголовки завжди перезаписуються шлюзом, тож підставити їх ззовні клієнт
 * не може. Сервіс токен не перевіряє — лише читає результат перевірки.
 */
final readonly class ActorResolver
{
    public const USER_HEADER = 'X-User-Id';
    public const ROLE_HEADER = 'X-User-Role';
    public const SUPPLIER_HEADER = 'X-Supplier-Id';
    public const STORE_IDS_HEADER = 'X-Store-Ids';
    public const CONTOUR_HEADER = 'X-Contour';

    /**
     * @throws AccessDeniedException якщо ідентичності немає або вона суперечлива
     */
    public function fromRequest(Request $request): Actor
    {
        $userId = trim((string) $request->headers->get(self::USER_HEADER, ''));
        $roleValue = trim((string) $request->headers->get(self::ROLE_HEADER, ''));

        if ('' === $userId || '' === $roleValue) {
            throw AccessDeniedException::missingIdentity();
        }

        $role = Role::tryFrom($roleValue);
        if (null === $role) {
            throw AccessDeniedException::unknownRole($roleValue);
        }

        $contour = $this->resolveContour($request, $role);

        $supplierId = trim((string) $request->headers->get(self::SUPPLIER_HEADER, ''));
        if ($role->isSupplier() && '' === $supplierId) {
            throw AccessDeniedException::missingSupplier();
        }

        return new Actor(
            userId: $userId,
            role: $role,
            contour: $contour,
            supplierId: '' === $supplierId ? null : $supplierId,
            storeIds: $this->storeIds($request),
        );
    }

    /**
     * Захист у глибину: заголовок контуру має збігатися з контуром самої ролі.
     * Відсутній заголовок — контур виводиться з ролі (сумісність зі старими
     * розгортаннями шлюзу), суперечливий — відмова.
     */
    private function resolveContour(Request $request, Role $role): Contour
    {
        $raw = trim((string) $request->headers->get(self::CONTOUR_HEADER, ''));
        if ('' === $raw) {
            return $role->contour();
        }

        $contour = Contour::tryFrom($raw);
        if (null === $contour || $contour !== $role->contour()) {
            throw AccessDeniedException::contourMismatch();
        }

        return $contour;
    }

    /**
     * Перелік магазинів скоупу. Порожній заголовок дає порожній перелік —
     * і для магазинних ролей це нуль доступу (RBAC-13), а не «всі магазини».
     *
     * @return list<string>
     */
    private function storeIds(Request $request): array
    {
        $raw = trim((string) $request->headers->get(self::STORE_IDS_HEADER, ''));
        if ('' === $raw) {
            return [];
        }

        $result = [];
        foreach (explode(',', $raw) as $storeId) {
            $storeId = trim($storeId);
            if ('' !== $storeId && !in_array($storeId, $result, true)) {
                $result[] = $storeId;
            }
        }

        return $result;
    }
}
