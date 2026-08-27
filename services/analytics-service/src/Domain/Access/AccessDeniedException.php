<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Exception\AnalyticsException;

/**
 * Актор не має права читати цю вибірку аналітики: чужий контур, роль без
 * доступу до дашбордів або магазин поза скоупом X-Store-Ids (RBAC-13).
 *
 * Віддається як RFC 7807 із кодом ANALYTICS_ACCESS_DENIED і статусом 403.
 */
final class AccessDeniedException extends \RuntimeException implements AnalyticsException
{
    public const ERROR_CODE = 'ANALYTICS_ACCESS_DENIED';

    public static function missingIdentity(): self
    {
        return new self('Запит без ідентичності користувача');
    }

    public static function unknownRole(string $role): self
    {
        return new self(sprintf('Невідома роль «%s»', $role));
    }

    public static function contourMismatch(): self
    {
        return new self('Роль запиту не належить контуру, вказаному в X-Contour');
    }

    public static function missingSupplier(): self
    {
        return new self('Для ролі постачальника обовʼязковий заголовок X-Supplier-Id');
    }

    /** Аналітика — інструмент staff-контуру: постачальник і водій її не читають. */
    public static function analyticsIsStaffOnly(Role $role): self
    {
        return new self(sprintf('Роль «%s» не має доступу до аналітики', $role->value));
    }

    /**
     * RBAC-13: магазинна роль без жодного магазину в скоупі. Порожній перелік
     * НЕ означає «вся мережа» — доступу немає до жодного магазину.
     */
    public static function emptyStoreScope(): self
    {
        return new self('Обліковий запис не привʼязаний до жодного магазину: аналітика недоступна');
    }

    public static function foreignStore(string $storeId): self
    {
        return new self(sprintf('Магазин «%s» поза скоупом облікового запису', $storeId));
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function title(): string
    {
        return 'Недостатньо прав';
    }
}
