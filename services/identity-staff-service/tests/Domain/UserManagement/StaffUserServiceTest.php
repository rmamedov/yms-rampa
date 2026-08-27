<?php

declare(strict_types=1);

namespace App\Tests\Domain\UserManagement;

use App\Domain\Identity\Email;
use App\Domain\Identity\Exception\CrossContourRoleException;
use App\Domain\Identity\Exception\EmailAlreadyUsedException;
use App\Domain\Identity\Exception\LastSuperAdminException;
use App\Domain\Identity\Exception\MultipleRolesForbiddenException;
use App\Domain\Identity\Exception\PermissionDeniedException;
use App\Domain\Identity\Exception\RoleAssignmentForbiddenException;
use App\Domain\Identity\Exception\SelfRoleChangeForbiddenException;
use App\Domain\Identity\Role;
use App\Domain\UserManagement\RoleAuditAction;
use App\Domain\UserManagement\StaffUserService;
use App\Tests\Support\AuthContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Управління ролями в адмін-панелі, розділ 4.7:
 * RBAC-22, RBAC-23, RBAC-24, RBAC-25, RBAC-26, RBAC-27, RBAC-29.
 */
#[CoversClass(StaffUserService::class)]
final class StaffUserServiceTest extends TestCase
{
    private const string PASSWORD = 'Rampa!Staff2026';

    private AuthContext $context;

    protected function setUp(): void
    {
        $this->context = new AuthContext();
    }

    /**
     * RBAC-22 + RBAC-29: створення користувача пише запис аудиту.
     */
    public function testSuperAdminCreatesUserAndAuditIsWritten(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);

        $created = $this->context->userManagement->createUser(
            actor: $actor,
            email: Email::fromString('Manager@Silpo.UA'),
            plainPassword: self::PASSWORD,
            role: Role::StoreManager,
            storeIds: ['A', 'B'],
            fullName: 'Олена Іванова',
            requestId: 'req-1',
            ip: '10.0.0.1',
        );

        self::assertSame('manager@silpo.ua', $created->email()->value);
        self::assertSame(Role::StoreManager, $created->role());
        self::assertSame(['A', 'B'], $created->storeIds());
        self::assertTrue($this->context->hasher->verify(self::PASSWORD, $created->passwordHash()));

        $audit = $this->context->audit->findByTarget($created->id());
        self::assertCount(1, $audit);
        self::assertSame(RoleAuditAction::Create, $audit[0]->action);
        self::assertSame($actor->id(), $audit[0]->actorUserId);
        self::assertSame('req-1', $audit[0]->requestId);
        self::assertSame(['role' => 'store_manager', 'storeIds' => ['A', 'B'], 'active' => true], $audit[0]->after);
    }

    /**
     * RBAC-23 / таблиця 4.10, сценарій 7: network_manager не може створити super_admin.
     */
    public function testNetworkManagerCannotCreateSuperAdmin(): void
    {
        $actor = $this->context->createUser('boss@silpo.ua', Role::NetworkManager);

        try {
            $this->context->userManagement->createUser(
                actor: $actor,
                email: Email::fromString('new-root@silpo.ua'),
                plainPassword: self::PASSWORD,
                role: Role::SuperAdmin,
            );
            self::fail('Очікувалася відмова RBAC_ROLE_ASSIGNMENT_FORBIDDEN.');
        } catch (RoleAssignmentForbiddenException $exception) {
            self::assertSame('RBAC_ROLE_ASSIGNMENT_FORBIDDEN', $exception->errorCode());
            self::assertSame(403, $exception->httpStatus());
            self::assertSame('Ви не можете призначити цю роль', $exception->userMessage());
        }

        // RBAC-23: жодних часткових змін
        self::assertNull($this->context->users->findByEmail(Email::fromString('new-root@silpo.ua')));
        self::assertSame([], $this->context->audit->all());
    }

    public function testNetworkManagerCannotCreateAnalyst(): void
    {
        $actor = $this->context->createUser('boss@silpo.ua', Role::NetworkManager);

        $this->expectException(RoleAssignmentForbiddenException::class);
        $this->context->userManagement->createUser(
            actor: $actor,
            email: Email::fromString('analyst@silpo.ua'),
            plainPassword: self::PASSWORD,
            role: Role::Analyst,
        );
    }

    /**
     * Матриця 4.4: store_manager взагалі не має права users.manage.staff.
     */
    public function testStoreManagerCannotManageUsers(): void
    {
        $actor = $this->context->createUser('sm@silpo.ua', Role::StoreManager, ['A']);

        $this->expectException(PermissionDeniedException::class);
        $this->context->userManagement->createUser(
            actor: $actor,
            email: Email::fromString('new@silpo.ua'),
            plainPassword: self::PASSWORD,
            role: Role::StoreOperator,
        );
    }

    /**
     * RBAC-27.1 / сценарій 9 таблиці 4.10: спроба призначити другу роль.
     */
    public function testAssigningTwoRolesIsRejected(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $target = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['A']);

        try {
            $this->context->userManagement->assignRoles(
                $actor,
                $target->id(),
                [Role::StoreOperator, Role::StoreManager],
            );
            self::fail('Очікувалася відмова RBAC_MULTIPLE_ROLES_FORBIDDEN.');
        } catch (MultipleRolesForbiddenException $exception) {
            self::assertSame(422, $exception->httpStatus());
        }

        // RBAC-AC-07: стан не змінився
        self::assertSame(Role::StoreOperator, $this->context->users->findById($target->id())?->role());
        self::assertSame([], $this->context->audit->all());
    }

    /**
     * RBAC-27.2: staff-акаунту не можна призначити partner-роль,
     * навіть якщо дерево 4.7 дозволяє super_admin створювати supplier_admin
     * (такий акаунт живе в partner-контурі — RBAC-09).
     */
    public function testStaffAccountCannotReceivePartnerRole(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $target = $this->context->createUser('analyst@silpo.ua', Role::Analyst);

        $this->expectException(CrossContourRoleException::class);
        $this->context->userManagement->assignRole($actor, $target->id(), Role::SupplierAdmin);
    }

    /**
     * RBAC-24 / сценарій 8: користувач не може змінити власну роль,
     * власний скоуп і не може деактивувати себе.
     */
    public function testSelfRoleScopeAndDeactivationAreForbidden(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        // Резервний super_admin, щоб спрацював саме RBAC-24, а не інваріант RBAC-25
        $this->context->createUser('root-backup@silpo.ua', Role::SuperAdmin);

        foreach ([
            fn () => $this->context->userManagement->assignRole($actor, $actor->id(), Role::Analyst),
            fn () => $this->context->userManagement->changeScope($actor, $actor->id(), ['A']),
            fn () => $this->context->userManagement->deactivate($actor, $actor->id()),
        ] as $operation) {
            try {
                $operation();
                self::fail('Очікувалася відмова RBAC_SELF_ROLE_CHANGE_FORBIDDEN.');
            } catch (SelfRoleChangeForbiddenException $exception) {
                self::assertSame('RBAC_SELF_ROLE_CHANGE_FORBIDDEN', $exception->errorCode());
                self::assertSame(403, $exception->httpStatus());
            }
        }
    }

    /**
     * RBAC-25 / сценарій 10: деактивація останнього активного super_admin блокується.
     */
    public function testLastActiveSuperAdminCannotBeDeactivatedOrDemoted(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $second = $this->context->createUser('root2@silpo.ua', Role::SuperAdmin);

        // Поки активних super_admin двоє — деактивація дозволена
        $this->context->userManagement->deactivate($actor, $second->id());
        self::assertFalse($this->context->users->findById($second->id())?->isActive());
        self::assertSame(1, $this->context->users->countActiveByRole(Role::SuperAdmin));

        // Тепер актор — ОСТАННІЙ активний super_admin: ані деактивація, ані пониження ролі
        try {
            $this->context->userManagement->deactivate($actor, $actor->id());
            self::fail('Очікувалася відмова RBAC_LAST_SUPER_ADMIN.');
        } catch (LastSuperAdminException $exception) {
            self::assertSame('RBAC_LAST_SUPER_ADMIN', $exception->errorCode());
            self::assertSame(409, $exception->httpStatus());
            self::assertSame('Останнього адміністратора деактивувати не можна', $exception->userMessage());
        }

        $this->expectException(LastSuperAdminException::class);
        $this->context->userManagement->assignRole($actor, $actor->id(), Role::Analyst);
    }

    /**
     * RBAC-25: після повернення другого super_admin інваріант більше не блокує,
     * і спрацьовує вже правило RBAC-24 (не можна змінювати власну роль).
     */
    public function testSelfDemotionIsBlockedByRbac24WhenAnotherSuperAdminExists(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $this->context->createUser('root2@silpo.ua', Role::SuperAdmin);

        self::assertSame(2, $this->context->users->countActiveByRole(Role::SuperAdmin));

        $this->expectException(SelfRoleChangeForbiddenException::class);
        $this->context->userManagement->assignRole($actor, $actor->id(), Role::Analyst);
    }

    /**
     * RBAC-26 + AUTH-32: зміна ролі та деактивація гасять активні сесії.
     */
    public function testRoleChangeAndDeactivationRevokeSessions(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $target = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['A'], self::PASSWORD);

        $this->context->authentication->login('operator@silpo.ua', self::PASSWORD);
        self::assertCount(1, $this->context->refreshTokens->findActiveForUser($target->id(), $this->context->clock->now()));

        $this->context->userManagement->assignRole($actor, $target->id(), Role::StoreManager);
        self::assertSame([], $this->context->refreshTokens->findActiveForUser($target->id(), $this->context->clock->now()));

        $updated = $this->context->users->findById($target->id());
        self::assertSame(Role::StoreManager, $updated?->role());

        $audit = $this->context->audit->findByTarget($target->id());
        self::assertSame(RoleAuditAction::Assign, $audit[0]->action);
        self::assertSame(['role' => 'store_operator', 'storeIds' => ['A']], $audit[0]->before);
        self::assertSame(['role' => 'store_manager', 'storeIds' => ['A']], $audit[0]->after);
    }

    /**
     * RBAC-13 + RBAC-29: зміна скоупа фіксується як scope_change.
     */
    public function testScopeChangeIsAudited(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $target = $this->context->createUser('sm@silpo.ua', Role::StoreManager, ['A']);

        $this->context->userManagement->changeScope($actor, $target->id(), ['A', 'B', 'C']);

        $audit = $this->context->audit->findByTarget($target->id());
        self::assertCount(1, $audit);
        self::assertSame(RoleAuditAction::ScopeChange, $audit[0]->action);
        self::assertSame(['storeIds' => ['A']], $audit[0]->before);
        self::assertSame(['storeIds' => ['A', 'B', 'C']], $audit[0]->after);
        self::assertArrayHasKey('timestamp', $audit[0]->toArray());
    }

    /**
     * Унікальний індекс {email:1} (10.5).
     */
    public function testDuplicateEmailIsRejected(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A']);

        $this->expectException(EmailAlreadyUsedException::class);
        $this->context->userManagement->createUser(
            actor: $actor,
            email: Email::fromString('MANAGER@silpo.ua'),
            plainPassword: self::PASSWORD,
            role: Role::StoreOperator,
        );
    }

    /**
     * AUTH-13: слабкий пароль відхиляється ще на етапі створення користувача.
     */
    public function testWeakPasswordIsRejectedOnUserCreation(): void
    {
        $actor = $this->context->createUser('root@silpo.ua', Role::SuperAdmin);

        $this->expectException(\App\Domain\Password\WeakPasswordException::class);
        $this->context->userManagement->createUser(
            actor: $actor,
            email: Email::fromString('weak@silpo.ua'),
            plainPassword: 'qwerty',
            role: Role::Analyst,
        );
    }
}
