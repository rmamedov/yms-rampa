<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Shared\DomainException;

/**
 * Актор не має права на цю дію: немає ідентичності, чужий контур, роль поза
 * матрицею 4.4 або спроба вийти за межі свого скоупа — HTTP 403.
 *
 * Свідомо НЕ 401: токен перевіряє шлюз, і 401 віддає саме він. Сюди запит
 * доходить уже «автентифікованим», тож будь-яка відмова тут — це відсутність
 * прав, а не відсутність автентифікації.
 */
final class AccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'ACCESS_DENIED';

    public function __construct(string $message = 'Недостатньо прав для цієї дії')
    {
        parent::__construct($message, self::ERROR_CODE, 'Недостатньо прав');
    }

    /** Заголовків ідентичності немає — запит не пройшов через шлюз. */
    public static function missingIdentity(): self
    {
        return new self('Запит без ідентичності користувача (заголовки X-User-Id / X-User-Role від api-gateway).');
    }

    public static function unknownRole(string $role): self
    {
        return new self(\sprintf('Невідома роль «%s».', $role));
    }

    public static function contourMismatch(): self
    {
        return new self('Роль запиту не належить контуру, вказаному в X-Contour.');
    }

    /**
     * Порожній X-Supplier-Id для ролі постачальника — це ВІДМОВА, а не доступ
     * до всіх постачальників: кабінет завжди працює в межах свого тенанта.
     */
    public static function missingSupplier(): self
    {
        return new self('Для ролі постачальника обовʼязковий непорожній заголовок X-Supplier-Id.');
    }

    public static function permissionDenied(Role $role, Permission $permission): self
    {
        return new self(\sprintf('Роль «%s» не має права «%s».', $role->value, $permission->value));
    }

    /** Право надане лише в межах скоупа, а запит вимагає мережевого доступу. */
    public static function networkScopeRequired(Role $role, Permission $permission): self
    {
        return new self(\sprintf(
            'Право «%s» надане ролі «%s» лише в межах власного скоупа — мережевий доступ заборонений.',
            $permission->value,
            $role->value,
        ));
    }

    public static function foreignSupplier(string $supplierId): self
    {
        return new self(\sprintf('Постачальник «%s» поза скоупом облікового запису.', $supplierId));
    }

    /**
     * RBAC-13: магазинна роль без жодного магазину в скоупі. Порожній перелік
     * НЕ означає «вся мережа» — доступу немає до жодного магазину.
     */
    public static function emptyStoreScope(): self
    {
        return new self('Обліковий запис не привʼязаний до жодного магазину.');
    }

    public static function foreignStore(string $storeId): self
    {
        return new self(\sprintf('Магазин «%s» поза скоупом облікового запису.', $storeId));
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
