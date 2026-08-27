<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Shared\DomainException;

/**
 * Відмова другого рубежу авторизації (RBAC-20) → HTTP 403.
 *
 * Коди відповідають таблиці 4.10 SRS:
 *  - RBAC_SCOPE_VIOLATION — право є, але ресурс поза скоупом користувача (RBAC-18);
 *  - ACCESS_DENIED — ідентичність відсутня, невідома або не відповідає контуру
 *    маршруту (запит в обхід шлюзу або підроблені заголовки).
 */
final class AccessDeniedException extends DomainException
{
    public const string SCOPE_VIOLATION = 'RBAC_SCOPE_VIOLATION';
    public const string ACCESS_DENIED = 'ACCESS_DENIED';

    public function httpStatus(): int
    {
        return 403;
    }

    public function title(): string
    {
        return 'Доступ заборонено';
    }

    /** Запит без службових заголовків ідентичності: довіри «за замовчуванням» немає (RBAC-02). */
    public static function missingIdentity(): self
    {
        return new self('Запит без ідентичності користувача', self::ACCESS_DENIED);
    }

    public static function unknownRole(string $value): self
    {
        return new self(\sprintf('Невідома роль «%s»', $value), self::ACCESS_DENIED);
    }

    /** RBAC-14: скоуп ролей постачальника задає supplierId, тому порожній заголовок — відмова. */
    public static function supplierIdRequired(): self
    {
        return new self('Для ролі постачальника обовʼязковий заголовок X-Supplier-Id', self::ACCESS_DENIED);
    }

    /** Заголовок X-Contour суперечить ролі — ознака підробки або розсинхрону шлюзу. */
    public static function contourMismatch(string $contour, Role $role): self
    {
        return new self(
            \sprintf('Контур «%s» не відповідає ролі «%s»', $contour, $role->value),
            self::ACCESS_DENIED,
        );
    }

    /** RBAC-19: роль не належить до групи маршрутів (staff ↔ partner). */
    public static function wrongContour(Contour $expected, Role $role): self
    {
        return new self(
            \sprintf('Роль «%s» не має доступу до маршрутів контуру «%s»', $role->value, $expected->value),
            self::ACCESS_DENIED,
        );
    }

    /**
     * RBAC-18 / таблиця 4.10, сценарій 4: дія над магазином поза скоупом.
     * Для ЧИТАННЯ одиничного магазину замість цього повертається 404 —
     * існування магазину поза скоупом не розкривається.
     */
    public static function storeOutOfScope(string $storeId): self
    {
        return new self(
            'Дія недоступна для цього магазину',
            self::SCOPE_VIOLATION,
            ['storeId' => $storeId],
        );
    }
}
