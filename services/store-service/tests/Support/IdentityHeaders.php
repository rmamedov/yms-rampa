<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Службові заголовки ідентичності, які api-gateway підставляє з відповіді
 * `GET /internal/v1/auth/verify` identity-сервісів.
 *
 * Ключі — у форматі $_SERVER (HTTP_*), як їх приймає Request::create().
 * Контракт: X-User-Id, X-User-Role, X-Supplier-Id, X-Store-Ids, X-Contour.
 */
final class IdentityHeaders
{
    private function __construct()
    {
    }

    /**
     * Ідентичність staff-контуру.
     *
     * @param list<string> $storeIds перелік магазинів у скоупі; для мережевих ролей завжди порожній
     *
     * @return array<string, string>
     */
    public static function staff(string $role = 'super_admin', array $storeIds = [], string $userId = 'staff-1'): array
    {
        return self::raw(
            userId: $userId,
            role: $role,
            supplierId: '',
            // Контракт: перелік через кому БЕЗ пробілів.
            storeIds: implode(',', $storeIds),
            contour: 'staff',
        );
    }

    /**
     * Ідентичність partner-контуру.
     *
     * @param list<string> $storeIds
     *
     * @return array<string, string>
     */
    public static function supplier(
        string $supplierId = 'supplier-1',
        string $role = 'supplier_operator',
        array $storeIds = [],
        string $userId = 'partner-1',
    ): array {
        return self::raw(
            userId: $userId,
            role: $role,
            supplierId: $supplierId,
            storeIds: implode(',', $storeIds),
            contour: 'partner',
        );
    }

    /**
     * Довільний набір заголовків — для негативних сценаріїв.
     *
     * @return array<string, string>
     */
    public static function raw(
        string $userId,
        string $role,
        string $supplierId = '',
        string $storeIds = '',
        string $contour = '',
    ): array {
        return [
            'HTTP_X_USER_ID' => $userId,
            'HTTP_X_USER_ROLE' => $role,
            'HTTP_X_SUPPLIER_ID' => $supplierId,
            'HTTP_X_STORE_IDS' => $storeIds,
            'HTTP_X_CONTOUR' => $contour,
        ];
    }
}
