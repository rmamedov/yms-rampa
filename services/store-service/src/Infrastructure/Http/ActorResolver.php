<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Contour;
use App\Domain\Access\Role;
use Symfony\Component\HttpFoundation\Request;

/**
 * Розбір ідентичності запиту зі службових заголовків api-gateway.
 *
 * ЄДИНИЙ КОНТРАКТ ІДЕНТИЧНОСТІ. nginx робить на кожен запит підзапит
 * `GET /internal/v1/auth/verify` до identity-staff-service або
 * identity-partner-service і додає до проксійованого запиту:
 *
 *   X-User-Id     — ідентифікатор користувача;
 *   X-User-Role   — рівно ОДНА роль (RBAC-04);
 *   X-Supplier-Id — постачальник; ПОРОЖНІЙ рядок, якщо не застосовно (staff);
 *   X-Store-Ids   — магазини скоупу через кому без пробілів («S-01,S-02»);
 *                   ПОРОЖНІЙ рядок, якщо не застосовно;
 *   X-Contour     — staff | partner.
 *
 * Мікросервіс JWT не перевіряє, але й не довіряє шлюзу сліпо (RBAC-20):
 * запит без ідентичності або з неузгодженими заголовками відхиляється,
 * а скоуп перевіряється повторно тут (див. Actor::storeScope).
 */
final readonly class ActorResolver
{
    public const string USER_HEADER = 'X-User-Id';
    public const string ROLE_HEADER = 'X-User-Role';
    public const string SUPPLIER_HEADER = 'X-Supplier-Id';
    public const string STORE_IDS_HEADER = 'X-Store-Ids';
    public const string CONTOUR_HEADER = 'X-Contour';

    public function fromRequest(Request $request): Actor
    {
        $userId = trim((string) $request->headers->get(self::USER_HEADER, ''));
        $roleValue = trim((string) $request->headers->get(self::ROLE_HEADER, ''));

        if ('' === $userId || '' === $roleValue) {
            throw AccessDeniedException::missingIdentity();
        }

        $role = Role::tryFrom($roleValue);

        if (!$role instanceof Role) {
            throw AccessDeniedException::unknownRole($roleValue);
        }

        $supplierId = trim((string) $request->headers->get(self::SUPPLIER_HEADER, ''));

        // RBAC-14: у партнерському контурі скоуп задає supplierId (у водія — теж),
        // тому порожній заголовок означає непридатну до перевірки ідентичність.
        if (Contour::Partner === $role->contour() && '' === $supplierId) {
            throw AccessDeniedException::supplierIdRequired();
        }

        return new Actor(
            userId: $userId,
            role: $role,
            // У staff-контурі постачальника не існує: значення ігнорується,
            // навіть якщо шлюз надіслав непорожній заголовок.
            supplierId: Contour::Staff === $role->contour() || '' === $supplierId ? null : $supplierId,
            storeIds: self::storeIds($request),
            contour: $this->contour($request, $role),
        );
    }

    /** Маршрути /api/admin/v1/** — лише staff-контур (RBAC-19). */
    public function staff(Request $request): Actor
    {
        return $this->inContour($request, Contour::Staff);
    }

    /**
     * Маршрути /api/supplier/v1/** — лише кабінет постачальника.
     * Роль driver працює з /api/driver/v1/**, каталог магазинів їй не адресований.
     */
    public function supplier(Request $request): Actor
    {
        $actor = $this->fromRequest($request);

        if (!$actor->role->isSupplier()) {
            throw AccessDeniedException::wrongContour(Contour::Partner, $actor->role);
        }

        return $actor;
    }

    private function inContour(Request $request, Contour $expected): Actor
    {
        $actor = $this->fromRequest($request);

        if ($expected !== $actor->contour) {
            throw AccessDeniedException::wrongContour($expected, $actor->role);
        }

        return $actor;
    }

    /**
     * X-Contour перевіряється на узгодженість із роллю: розбіжність — ознака
     * підроблених заголовків (запит в обхід шлюзу). Якщо заголовка немає,
     * контур однозначно визначає роль.
     */
    private function contour(Request $request, Role $role): Contour
    {
        $value = trim((string) $request->headers->get(self::CONTOUR_HEADER, ''));

        if ('' === $value) {
            return $role->contour();
        }

        $contour = Contour::tryFrom($value);

        if (!$contour instanceof Contour || $contour !== $role->contour()) {
            throw AccessDeniedException::contourMismatch($value, $role);
        }

        return $contour;
    }

    /**
     * «S-01,S-02» → ['S-01', 'S-02']; порожній рядок → [].
     *
     * Порожній результат НЕ означає «усі магазини»: як його трактувати,
     * вирішує роль — див. Actor::storeScope() і RBAC-13.
     *
     * @return list<string>
     */
    private static function storeIds(Request $request): array
    {
        $header = trim((string) $request->headers->get(self::STORE_IDS_HEADER, ''));

        if ('' === $header) {
            return [];
        }

        $ids = [];

        foreach (explode(',', $header) as $id) {
            $id = trim($id);

            if ('' !== $id && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
