<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use App\Domain\Identity\AccessDecider;
use App\Domain\Identity\Permission;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserRepository;

/**
 * Читання журналу аудиту (RBAC-29, RBAC-31).
 *
 * Записи в `role_audit` велися від початку, але назовні не публікувалися:
 * ролі з правом `audit.read` (super_admin, network_manager) не мали жодного
 * способу подивитися, хто і що змінював.
 *
 * Ідентифікатори акторів і цілей замінюються на імена й e-mail тут, а не в
 * інтерфейсі: клієнт не має ходити по довіднику користувачів заради кожного
 * рядка журналу, а видалений/деактивований користувач має лишатися впізнаваним.
 */
final readonly class AuditLogService
{
    public const int DEFAULT_PER_PAGE = 20;
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private RoleAuditRepository $audit,
        private StaffUserRepository $users,
        private AccessDecider $accessDecider,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function page(
        StaffUser $actor,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?string $targetUserId = null,
        ?RoleAuditAction $action = null,
    ): array {
        $this->accessDecider->assertCan($actor, Permission::AuditRead);

        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $entries = $this->audit->recent($perPage, ($page - 1) * $perPage, $targetUserId, $action);
        $total = $this->audit->count($targetUserId, $action);

        return [
            'items' => array_map($this->present(...), $entries),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'actions' => array_map(
                static fn (RoleAuditAction $a): array => ['value' => $a->value, 'label' => self::actionLabel($a)],
                RoleAuditAction::cases(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RoleAuditEntry $entry): array
    {
        return $entry->toArray() + [
            'actorName' => $this->displayName($entry->actorUserId),
            'actorRoleLabel' => $entry->actorRole->label(),
            'targetName' => $this->displayName($entry->targetUserId),
            'actionLabel' => self::actionLabel($entry->action),
        ];
    }

    private function displayName(string $userId): string
    {
        $user = $this->users->findById($userId);

        // Користувача могли видалити — ідентифікатор чесніший за порожнє поле.
        return $user instanceof StaffUser ? $user->fullName() : $userId;
    }

    public static function actionLabel(RoleAuditAction $action): string
    {
        return match ($action) {
            RoleAuditAction::Create => 'Створення акаунта',
            RoleAuditAction::Assign => 'Зміна ролі',
            RoleAuditAction::ScopeChange => 'Зміна скоупа магазинів',
            RoleAuditAction::Deactivate => 'Деактивація',
            RoleAuditAction::Reactivate => 'Активація',
            RoleAuditAction::Rename => 'Зміна імені',
            RoleAuditAction::PasswordReset => 'Скидання пароля',
        };
    }
}
