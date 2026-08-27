<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use App\Domain\Auth\TokenService;
use App\Domain\Identity\AccessDecider;
use App\Domain\Identity\Email;
use App\Domain\Identity\Exception\EmailAlreadyUsedException;
use App\Domain\Identity\Exception\LastSuperAdminException;
use App\Domain\Identity\Exception\MultipleRolesForbiddenException;
use App\Domain\Identity\Exception\ResourceNotFoundException;
use App\Domain\Identity\Exception\RoleAssignmentForbiddenException;
use App\Domain\Identity\Exception\SelfRoleChangeForbiddenException;
use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserCriteria;
use App\Domain\Identity\StaffUserPage;
use App\Domain\Identity\StaffUserRepository;
use App\Domain\Password\PasswordGenerator;
use App\Domain\Password\PasswordPolicy;
use App\Domain\Password\PasswordHasher;
use App\Domain\Shared\Clock;

/**
 * Управління staff-користувачами в адмін-панелі (розділ 4.7).
 *
 * Реалізовані правила: RBAC-22 (дерево призначення), RBAC-23 (відмова поза деревом),
 * RBAC-24 (заборона змінювати власну роль/скоуп і деактивувати себе),
 * RBAC-25 (останній активний super_admin), RBAC-26 (зміна набирає чинності
 * не пізніше TTL access-токена, деактивація негайно інвалідує refresh),
 * RBAC-27.1 (друга роль), RBAC-29 (аудит).
 */
final readonly class StaffUserService
{
    public function __construct(
        private StaffUserRepository $users,
        private RoleAuditRepository $audit,
        private AccessDecider $accessDecider,
        private PasswordHasher $hasher,
        private PasswordPolicy $passwordPolicy,
        private Clock $clock,
        private ?TokenService $tokens = null,
        private ?PasswordGenerator $passwordGenerator = null,
    ) {
    }

    /**
     * Список користувачів для розділу «Користувачі» (4.7).
     *
     * RBAC-23: вибірка мовчки звужується до ролей, які актор має право
     * призначати, тому network_manager не бачить у списку ані super_admin,
     * ані інших network_manager — так само, як магазинна роль не бачить
     * чужих магазинів (RBAC-17).
     */
    public function listUsers(StaffUser $actor, StaffUserCriteria $criteria): StaffUserPage
    {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);

        return $this->users->search($criteria->restrictedTo($this->manageableRoles($actor)));
    }

    /**
     * Картка користувача.
     *
     * RBAC-18: акаунт, роль якого акторові недоступна, не показується
     * як «заборонено» — його існування взагалі не розкривається (404).
     */
    public function getUser(StaffUser $actor, string $targetUserId): StaffUser
    {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);

        $target = $this->requireUser($targetUserId);

        if (!\in_array($target->role(), $this->manageableRoles($actor), true)) {
            throw new ResourceNotFoundException('staff_user');
        }

        return $target;
    }

    /**
     * RBAC-22: створення користувача і призначення ролі за деревом 4.7.
     *
     * @param list<string> $storeIds
     */
    public function createUser(
        StaffUser $actor,
        Email $email,
        ?string $plainPassword,
        Role $role,
        array $storeIds = [],
        string $fullName = '',
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUser {
        return $this->createUserWithCredentials(
            $actor,
            $email,
            $plainPassword,
            $role,
            $storeIds,
            $fullName,
            $requestId,
            $ip,
        )->user;
    }

    /**
     * Те саме створення, але з паролем для ОДНОРАЗОВОГО показу в адмін-панелі.
     *
     * `$plainPassword === null` означає «згенеруй сам»: адміністратор не
     * вигадує пароль і не пересилає його каналом, який він не контролює.
     *
     * @param list<string> $storeIds
     */
    public function createUserWithCredentials(
        StaffUser $actor,
        Email $email,
        ?string $plainPassword,
        Role $role,
        array $storeIds = [],
        string $fullName = '',
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUserCredentials {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);
        $this->assertCanAssign($actor, $role);

        if (null !== $this->users->findByEmail($email)) {
            throw new EmailAlreadyUsedException($email->value);
        }

        $password = $this->resolvePassword($plainPassword, $email->value, $fullName);

        $now = $this->clock->now();
        $user = StaffUser::create(
            email: $email,
            passwordHash: $this->hasher->hash($password),
            role: $role,
            storeIds: $storeIds,
            now: $now,
            fullName: $fullName,
        );

        $this->users->save($user);

        $this->audit->append(new RoleAuditEntry(
            actorUserId: $actor->id(),
            actorRole: $actor->role(),
            targetUserId: $user->id(),
            action: RoleAuditAction::Create,
            before: [],
            after: ['role' => $role->value, 'storeIds' => $user->storeIds(), 'active' => true],
            timestamp: $now,
            requestId: $requestId,
            ip: $ip,
        ));

        return new StaffUserCredentials($user, $password);
    }

    /**
     * RBAC-04 / RBAC-27.1: єдиний вхід для призначення ролей ззовні.
     * Передача більше однієї ролі — 422 RBAC_MULTIPLE_ROLES_FORBIDDEN.
     *
     * @param list<Role> $roles
     */
    public function assignRoles(
        StaffUser $actor,
        string $targetUserId,
        array $roles,
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUser {
        if (1 !== \count($roles)) {
            throw new MultipleRolesForbiddenException(
                array_map(static fn (Role $role): string => $role->value, $roles),
            );
        }

        return $this->assignRole($actor, $targetUserId, $roles[array_key_first($roles)], $requestId, $ip);
    }

    /**
     * RBAC-23/RBAC-24/RBAC-25/RBAC-26.
     */
    public function assignRole(
        StaffUser $actor,
        string $targetUserId,
        Role $role,
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUser {
        $this->accessDecider->assertCan($actor, Permission::RolesAssign);

        // RBAC-23: дерево 4.7 перевіряється ПЕРШИМ, щоб актор із меншими правами
        // не дізнався зі статусу помилки нічого про стан цільового акаунта.
        $this->assertCanAssign($actor, $role);

        $target = $this->requireUser($targetUserId);
        $before = ['role' => $target->role()->value, 'storeIds' => $target->storeIds()];

        // RBAC-25: системний інваріант «щонайменше один активний super_admin»
        // перевіряється перед RBAC-24, бо стосується системи, а не актора:
        // єдиний super_admin не може понизити роль навіть сам собі (сценарій 10, 409).
        if (Role::SuperAdmin === $target->role() && Role::SuperAdmin !== $role) {
            $this->assertNotLastSuperAdmin($target);
        }

        // RBAC-24: користувач не може змінювати власну роль
        if ($actor->id() === $targetUserId) {
            throw new SelfRoleChangeForbiddenException();
        }

        $now = $this->clock->now();
        $target->changeRole($role, $now);
        $this->users->save($target);

        // RBAC-26: refresh має видати токен уже з новими клеймами
        $this->tokens?->revokeAllSessions($target->id());

        $this->audit->append(new RoleAuditEntry(
            actorUserId: $actor->id(),
            actorRole: $actor->role(),
            targetUserId: $target->id(),
            action: RoleAuditAction::Assign,
            before: $before,
            after: ['role' => $role->value, 'storeIds' => $target->storeIds()],
            timestamp: $now,
            requestId: $requestId,
            ip: $ip,
        ));

        return $target;
    }

    /**
     * RBAC-13/RBAC-24: зміна скоупа магазинів; власний скоуп змінювати не можна.
     *
     * @param list<string> $storeIds
     */
    public function changeScope(
        StaffUser $actor,
        string $targetUserId,
        array $storeIds,
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUser {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);

        if ($actor->id() === $targetUserId) {
            throw new SelfRoleChangeForbiddenException();
        }

        $target = $this->requireUser($targetUserId);
        $this->assertCanAssign($actor, $target->role());

        $before = ['storeIds' => $target->storeIds()];
        $now = $this->clock->now();

        $target->changeScope($storeIds, $now);
        $this->users->save($target);

        $this->audit->append(new RoleAuditEntry(
            actorUserId: $actor->id(),
            actorRole: $actor->role(),
            targetUserId: $target->id(),
            action: RoleAuditAction::ScopeChange,
            before: $before,
            after: ['storeIds' => $target->storeIds()],
            timestamp: $now,
            requestId: $requestId,
            ip: $ip,
        ));

        return $target;
    }

    /**
     * RBAC-24/RBAC-25/RBAC-26 + AUTH-32.
     */
    public function deactivate(
        StaffUser $actor,
        string $targetUserId,
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUser {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);

        $target = $this->requireUser($targetUserId);
        // RBAC-22/23: керувати можна лише тими ролями, які актор має право призначати
        $this->assertCanAssign($actor, $target->role());

        // RBAC-25: останнього активного super_admin деактивувати не можна (409),
        // навіть якщо це спроба деактивувати самого себе.
        if (Role::SuperAdmin === $target->role()) {
            $this->assertNotLastSuperAdmin($target);
        }

        // RBAC-24: не можна деактивувати власний акаунт
        if ($actor->id() === $targetUserId) {
            throw new SelfRoleChangeForbiddenException();
        }

        $now = $this->clock->now();
        $target->deactivate($now);
        $this->users->save($target);

        // RBAC-26/AUTH-28: деактивація негайно інвалідує refresh-токени
        $this->tokens?->revokeAllSessions($target->id());

        $this->audit->append(new RoleAuditEntry(
            actorUserId: $actor->id(),
            actorRole: $actor->role(),
            targetUserId: $target->id(),
            action: RoleAuditAction::Deactivate,
            before: ['active' => true],
            after: ['active' => false],
            timestamp: $now,
            requestId: $requestId,
            ip: $ip,
        ));

        return $target;
    }

    public function reactivate(
        StaffUser $actor,
        string $targetUserId,
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUser {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);

        $target = $this->requireUser($targetUserId);
        $this->assertCanAssign($actor, $target->role());

        $now = $this->clock->now();
        $target->activate($now);
        $this->users->save($target);

        $this->audit->append(new RoleAuditEntry(
            actorUserId: $actor->id(),
            actorRole: $actor->role(),
            targetUserId: $target->id(),
            action: RoleAuditAction::Reactivate,
            before: ['active' => false],
            after: ['active' => true],
            timestamp: $now,
            requestId: $requestId,
            ip: $ip,
        ));

        return $target;
    }

    /**
     * Зміна повного імені. Роль і скоуп тут не змінюються — для них є
     * assignRole() і changeScope() зі своїми інваріантами RBAC-24/25.
     */
    public function rename(
        StaffUser $actor,
        string $targetUserId,
        string $fullName,
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUser {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);

        $target = $this->requireUser($targetUserId);
        $this->assertCanAssign($actor, $target->role());

        $before = ['fullName' => $target->fullName()];
        $now = $this->clock->now();

        $target->rename($fullName, $now);
        $this->users->save($target);

        $this->audit->append(new RoleAuditEntry(
            actorUserId: $actor->id(),
            actorRole: $actor->role(),
            targetUserId: $target->id(),
            action: RoleAuditAction::Rename,
            before: $before,
            after: ['fullName' => $target->fullName()],
            timestamp: $now,
            requestId: $requestId,
            ip: $ip,
        ));

        return $target;
    }

    /**
     * Перегенерація пароля адміністратором (розділ 4.7).
     *
     * AUTH-13: новий пароль проходить політику, включно з історією
     * останніх пʼяти хешів. AUTH-32/RBAC-26: усі сесії власника гасяться
     * негайно — старий пароль більше нікуди не пускає.
     */
    public function resetPassword(
        StaffUser $actor,
        string $targetUserId,
        ?string $plainPassword = null,
        ?string $requestId = null,
        ?string $ip = null,
    ): StaffUserCredentials {
        $this->accessDecider->assertCan($actor, Permission::UsersManageStaff);

        $target = $this->requireUser($targetUserId);
        $this->assertCanAssign($actor, $target->role());

        $password = $this->resolvePassword(
            $plainPassword,
            $target->email()->value,
            $target->fullName(),
            $target->passwordHistory(),
        );

        $now = $this->clock->now();
        $target->changePassword($this->hasher->hash($password), $now);
        $this->users->save($target);

        $this->tokens?->revokeAllSessions($target->id());

        $this->audit->append(new RoleAuditEntry(
            actorUserId: $actor->id(),
            actorRole: $actor->role(),
            targetUserId: $target->id(),
            action: RoleAuditAction::PasswordReset,
            before: [],
            // AUTH-61: у журналі — лише факт скидання, без пароля і без хеша
            after: ['passwordReset' => true],
            timestamp: $now,
            requestId: $requestId,
            ip: $ip,
        ));

        return new StaffUserCredentials($target, $password);
    }

    /**
     * Ролі, якими актор має право керувати за деревом 4.7, у межах
     * staff-контуру (supplier_admin із дерева живе в partner-контурі).
     *
     * @return list<Role>
     */
    public function manageableRoles(StaffUser $actor): array
    {
        return array_values(array_filter(
            $actor->role()->assignableRoles(),
            static fn (Role $role): bool => \in_array($role, Role::staffRoles(), true),
        ));
    }

    /**
     * Пароль або від адміністратора, або згенерований — в обох випадках
     * перевіряється НАЯВНОЮ політикою AUTH-13, власних правил тут немає.
     *
     * @param list<string> $passwordHistory
     */
    private function resolvePassword(
        ?string $plainPassword,
        string $email,
        string $fullName,
        array $passwordHistory = [],
    ): string {
        if (null === $plainPassword) {
            if (null === $this->passwordGenerator) {
                throw new ValidationException(
                    'Пароль обовʼязковий.',
                    ['Генератор паролів недоступний — вкажіть пароль явно'],
                );
            }

            return $this->passwordGenerator->generate($email, $fullName);
        }

        $this->passwordPolicy->assertValid($plainPassword, $email, $fullName, $passwordHistory);

        return $plainPassword;
    }

    private function assertCanAssign(StaffUser $actor, Role $role): void
    {
        // RBAC-23: будь-яка спроба призначення поза деревом 4.7 відхиляється
        // без часткового застосування змін.
        if (!$actor->role()->canAssign($role)) {
            throw new RoleAssignmentForbiddenException($actor->role(), $role);
        }
    }

    private function assertNotLastSuperAdmin(StaffUser $target): void
    {
        if (!$target->isActive()) {
            return;
        }

        if ($this->users->countActiveByRole(Role::SuperAdmin) <= 1) {
            throw new LastSuperAdminException();
        }
    }

    private function requireUser(string $userId): StaffUser
    {
        $user = $this->users->findById($userId);

        if (null === $user) {
            // RBAC-18: не розкриваємо існування ресурсу
            throw new ResourceNotFoundException('staff_user');
        }

        return $user;
    }
}
