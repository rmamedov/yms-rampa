<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Exception\ValidationException;

/**
 * Критерії серверної вибірки staff-користувачів для розділу «Користувачі»
 * адмін-панелі (4.7).
 *
 * Фільтри комбінуються за логікою AND. Розміри сторінки — ті самі, що й у
 * решті адмінських списків (UI-01): 20 / 50 / 100, усе інше — 422.
 *
 * RBAC-23: `manageableRoles` — це не фільтр інтерфейсу, а предикат безпеки.
 * Актор бачить лише ті облікові записи, роль яких він має право призначати
 * за деревом 4.7; колекція фільтрується МОВЧКИ (як і скоуп магазинів у
 * store-service), щоб зі списку не можна було дізнатися про існування
 * акаунтів вищого рівня.
 */
final readonly class StaffUserCriteria
{
    public const int DEFAULT_PER_PAGE = 20;

    /** @var list<int> дозволені розміри сторінки (UI-01) */
    public const array ALLOWED_PER_PAGE = [20, 50, 100];

    /**
     * @param Role|null       $role            фільтр за роллю; null — будь-яка
     * @param bool|null       $active          фільтр «активний / деактивований»; null — будь-який
     * @param string|null     $query           пошук за email або повним імʼям (підрядок, без урахування регістру)
     * @param list<Role>|null $manageableRoles RBAC-23: ролі, доступні акторові за деревом 4.7;
     *                                         null — без обмеження, порожній список — порожня вибірка
     */
    public function __construct(
        public ?Role $role = null,
        public ?bool $active = null,
        public ?string $query = null,
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?array $manageableRoles = null,
    ) {
    }

    /**
     * Розбір параметрів рядка запиту з валідацією за таблицею 4.10:
     * невідома роль, невідомий статус чи неприпустимий perPage — 422 VALIDATION_FAILED.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $perPage = isset($query['perPage']) ? (int) $query['perPage'] : self::DEFAULT_PER_PAGE;

        if (!\in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            throw new ValidationException(
                'Некоректний розмір сторінки.',
                [\sprintf('Розмір сторінки має бути одним із: %s', implode(', ', self::ALLOWED_PER_PAGE))],
            );
        }

        return new self(
            role: self::role($query),
            active: self::status($query),
            query: isset($query['q']) ? trim((string) $query['q']) : null,
            page: max(1, isset($query['page']) ? (int) $query['page'] : 1),
            perPage: $perPage,
        );
    }

    /**
     * RBAC-23: звуження вибірки до ролей, якими актор має право керувати.
     *
     * @param list<Role> $roles
     */
    public function restrictedTo(array $roles): self
    {
        return new self(
            role: $this->role,
            active: $this->active,
            query: $this->query,
            page: $this->page,
            perPage: $this->perPage,
            manageableRoles: array_values($roles),
        );
    }

    public function offset(): int
    {
        return max(0, $this->page - 1) * $this->perPage;
    }

    /**
     * Ролі, які реально потрапляють у вибірку: перетин фільтра за роллю
     * з дозволеними акторові ролями. Порожній масив = гарантовано порожня
     * вибірка (RBAC-23), як порожній storeIds для магазинних ролей.
     *
     * @return list<Role>
     */
    public function effectiveRoles(): array
    {
        $allowed = $this->manageableRoles ?? Role::staffRoles();

        if (null === $this->role) {
            return array_values($allowed);
        }

        return \in_array($this->role, $allowed, true) ? [$this->role] : [];
    }

    /**
     * Спільна логіка фільтрації для InMemory-реалізації сховища.
     */
    public function matches(StaffUser $user): bool
    {
        if (!\in_array($user->role(), $this->effectiveRoles(), true)) {
            return false;
        }

        if (null !== $this->active && $user->isActive() !== $this->active) {
            return false;
        }

        return $this->matchesQuery($user);
    }

    private function matchesQuery(StaffUser $user): bool
    {
        $query = trim((string) $this->query);

        if ('' === $query) {
            return true;
        }

        $needle = mb_strtolower($query);

        return str_contains(mb_strtolower($user->email()->value), $needle)
            || str_contains(mb_strtolower($user->fullName()), $needle);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function role(array $query): ?Role
    {
        $value = trim((string) ($query['role'] ?? ''));

        if ('' === $value) {
            return null;
        }

        $role = Role::tryFrom($value);

        // Ролі partner-контуру в staff-довіднику не існує (RBAC-27.2),
        // тому вони так само невідомі, як і вигадані значення.
        if (!$role instanceof Role || Contour::Staff !== $role->contour()) {
            throw new ValidationException(
                'Невідома роль у фільтрі.',
                [\sprintf(
                    'Невідома роль «%s»; допустимі: %s',
                    $value,
                    implode(', ', array_map(static fn (Role $r): string => $r->value, Role::staffRoles())),
                )],
            );
        }

        return $role;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function status(array $query): ?bool
    {
        $value = trim((string) ($query['status'] ?? ''));

        return match ($value) {
            '' => null,
            'active' => true,
            'inactive' => false,
            default => throw new ValidationException(
                'Невідомий статус у фільтрі.',
                [\sprintf('Невідомий статус «%s»; допустимі: active, inactive', $value)],
            ),
        };
    }
}
