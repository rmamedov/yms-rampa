<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Exception\CrossContourRoleException;
use App\Domain\Identity\Exception\MultipleRolesForbiddenException;
use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Shared\Uuid;

/**
 * Обліковий запис співробітника мережі — колекція `staff_users` БД `identity_staff` (10.5).
 *
 * Інваріанти:
 *  - RBAC-04 / DATA-36: рівно ОДНА роль; спроба призначити другу → RBAC_MULTIPLE_ROLES_FORBIDDEN;
 *  - RBAC-27.2: роль обовʼязково зі staff-контуру (крос-контурні комбінації заборонені);
 *  - RBAC-13 / DATA-36: для store_manager і store_operator порожній `storeIds` = НУЛЬ доступу,
 *    а не доступ до всієї мережі; скоуп «вся мережа» визначається роллю (RBAC-16).
 */
final class StaffUser
{
    /**
     * DATA-02: актуальна версія схеми документа.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * AUTH-13: глибина історії паролів — не повторювати останні 5.
     */
    public const int PASSWORD_HISTORY_SIZE = 5;

    /** @var list<string> */
    private array $storeIds;

    /** @var list<string> */
    private array $passwordHistory;

    /**
     * @param list<string> $storeIds
     * @param list<string> $passwordHistory хеші попередніх паролів (AUTH-13)
     */
    private function __construct(
        private readonly string $id,
        private Email $email,
        private string $passwordHash,
        private Role $role,
        array $storeIds,
        private bool $active,
        private bool $twoFactorEnabled,
        private ?string $totpSecret,
        private ?\DateTimeImmutable $lastLoginAt,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        array $passwordHistory = [],
        private ?\DateTimeImmutable $archivedAt = null,
        private string $fullName = '',
        private readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        $this->assertStaffRole($role);
        $this->storeIds = self::normalizeStoreIds($storeIds);
        $this->passwordHistory = array_values($passwordHistory);
    }

    /**
     * @param list<string> $storeIds
     */
    public static function create(
        Email $email,
        string $passwordHash,
        Role $role,
        array $storeIds,
        \DateTimeImmutable $now,
        string $fullName = '',
        ?string $id = null,
    ): self {
        if ('' === $passwordHash) {
            throw new ValidationException('Пароль обовʼязковий.', ['Хеш пароля не може бути порожнім']);
        }

        return new self(
            id: $id ?? Uuid::v4(),
            email: $email,
            passwordHash: $passwordHash,
            role: $role,
            storeIds: $storeIds,
            active: true,
            twoFactorEnabled: false,
            totpSecret: null,
            lastLoginAt: null,
            createdAt: $now,
            updatedAt: $now,
            fullName: $fullName,
        );
    }

    /**
     * Відновлення агрегату з документа MongoDB (без валідації бізнес-переходів).
     *
     * @param list<string> $storeIds
     * @param list<string> $passwordHistory
     */
    public static function restore(
        string $id,
        Email $email,
        string $passwordHash,
        Role $role,
        array $storeIds,
        bool $active,
        bool $twoFactorEnabled,
        ?string $totpSecret,
        ?\DateTimeImmutable $lastLoginAt,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        array $passwordHistory = [],
        ?\DateTimeImmutable $archivedAt = null,
        string $fullName = '',
        int $schemaVersion = self::SCHEMA_VERSION,
    ): self {
        return new self(
            id: $id,
            email: $email,
            passwordHash: $passwordHash,
            role: $role,
            storeIds: $storeIds,
            active: $active,
            twoFactorEnabled: $twoFactorEnabled,
            totpSecret: $totpSecret,
            lastLoginAt: $lastLoginAt,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            passwordHistory: $passwordHistory,
            archivedAt: $archivedAt,
            fullName: $fullName,
            schemaVersion: $schemaVersion,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): Email
    {
        return $this->email;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function role(): Role
    {
        return $this->role;
    }

    /**
     * @return list<string>
     */
    public function storeIds(): array
    {
        return $this->storeIds;
    }

    /**
     * @return list<string>
     */
    public function passwordHistory(): array
    {
        return $this->passwordHistory;
    }

    public function isActive(): bool
    {
        return $this->active && null === $this->archivedAt;
    }

    public function isTwoFactorEnabled(): bool
    {
        return $this->twoFactorEnabled;
    }

    public function totpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * RBAC-16: скоуп «вся мережа» визначається роллю, а не порожнім storeIds.
     */
    public function isNetworkWide(): bool
    {
        return $this->role->isNetworkWide();
    }

    /**
     * RBAC-13: перевірка належності магазину скоупу користувача.
     * Порожній масив storeIds у магазинної ролі означає нуль доступу.
     */
    public function hasStoreInScope(string $storeId): bool
    {
        if ($this->isNetworkWide()) {
            return true;
        }

        return \in_array($storeId, $this->storeIds, true);
    }

    /**
     * RBAC-04 / RBAC-27.1: єдина точка призначення ролі. Будь-яка спроба
     * передати більше однієї ролі відхиляється (422 RBAC_MULTIPLE_ROLES_FORBIDDEN),
     * навіть якщо ролі однакові — масив ролей у моделі не існує.
     *
     * @param list<Role> $roles
     */
    public function assignRoles(array $roles, \DateTimeImmutable $now): void
    {
        if (1 !== \count($roles)) {
            throw new MultipleRolesForbiddenException(
                array_map(static fn (Role $role): string => $role->value, $roles),
            );
        }

        $this->changeRole($roles[array_key_first($roles)], $now);
    }

    /**
     * RBAC-26: зміна ролі набирає чинності не пізніше TTL access-токена;
     * refresh видає токен уже з новими клеймами.
     */
    public function changeRole(Role $role, \DateTimeImmutable $now): void
    {
        $this->assertStaffRole($role);

        $this->role = $role;
        $this->touch($now);
    }

    /**
     * RBAC-13: зміна скоупа магазинів.
     *
     * @param list<string> $storeIds
     */
    public function changeScope(array $storeIds, \DateTimeImmutable $now): void
    {
        $this->storeIds = self::normalizeStoreIds($storeIds);
        $this->touch($now);
    }

    /**
     * AUTH-13/AUTH-14: зміна пароля з веденням історії останніх 5 хешів.
     */
    public function changePassword(string $newHash, \DateTimeImmutable $now): void
    {
        if ('' === $newHash) {
            throw new ValidationException('Пароль обовʼязковий.', ['Хеш пароля не може бути порожнім']);
        }

        array_unshift($this->passwordHistory, $this->passwordHash);
        $this->passwordHistory = \array_slice($this->passwordHistory, 0, self::PASSWORD_HISTORY_SIZE);
        $this->passwordHash = $newHash;
        $this->touch($now);
    }

    /**
     * AUTH-60: автоматичний rehash при логіні після посилення параметрів argon2id.
     */
    public function rehashPassword(string $newHash, \DateTimeImmutable $now): void
    {
        $this->passwordHash = $newHash;
        $this->touch($now);
    }

    /**
     * AUTH-12 / RBAC-26: деактивація забороняє логін і негайно інвалідує сесії.
     */
    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->touch($now);
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->archivedAt = null;
        $this->touch($now);
    }

    /**
     * DATA-03: видалення бізнес-сутностей — тільки soft delete.
     */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt = $now;
        $this->active = false;
        $this->touch($now);
    }

    /**
     * AUTH-15: увімкнення TOTP після підтвердження першим кодом.
     * AUTH-63: секрет зберігається шифровано — шифрування виконує шар інфраструктури.
     */
    public function enableTwoFactor(string $secret, \DateTimeImmutable $now): void
    {
        if ('' === $secret) {
            throw new ValidationException('Секрет 2FA обовʼязковий.', ['Порожній секрет TOTP']);
        }

        $this->totpSecret = $secret;
        $this->twoFactorEnabled = true;
        $this->touch($now);
    }

    public function disableTwoFactor(\DateTimeImmutable $now): void
    {
        $this->totpSecret = null;
        $this->twoFactorEnabled = false;
        $this->touch($now);
    }

    public function registerSuccessfulLogin(\DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
        $this->touch($now);
    }

    public function changeEmail(Email $email, \DateTimeImmutable $now): void
    {
        $this->email = $email;
        $this->touch($now);
    }

    public function rename(string $fullName, \DateTimeImmutable $now): void
    {
        $this->fullName = $fullName;
        $this->touch($now);
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    private function assertStaffRole(Role $role): void
    {
        if (Contour::Staff !== $role->contour()) {
            throw new CrossContourRoleException($role);
        }
    }

    /**
     * @param list<string> $storeIds
     *
     * @return list<string>
     */
    private static function normalizeStoreIds(array $storeIds): array
    {
        $normalized = [];
        foreach ($storeIds as $storeId) {
            $storeId = trim($storeId);
            if ('' === $storeId) {
                throw new ValidationException(
                    'Некоректний перелік магазинів.',
                    ['Ідентифікатор магазину не може бути порожнім'],
                );
            }
            if (!\in_array($storeId, $normalized, true)) {
                $normalized[] = $storeId;
            }
        }

        return $normalized;
    }
}
