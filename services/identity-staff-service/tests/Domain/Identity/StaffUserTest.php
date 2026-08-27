<?php

declare(strict_types=1);

namespace App\Tests\Domain\Identity;

use App\Domain\Identity\Email;
use App\Domain\Identity\Exception\CrossContourRoleException;
use App\Domain\Identity\Exception\MultipleRolesForbiddenException;
use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Shared\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Інваріанти агрегату staff_users (10.5, DATA-36, RBAC-04, RBAC-27).
 */
#[CoversClass(StaffUser::class)]
#[CoversClass(Email::class)]
final class StaffUserTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');
    }

    private function user(Role $role = Role::StoreManager, array $storeIds = ['A']): StaffUser
    {
        return StaffUser::create(
            email: Email::fromString('Ivan.Petrenko@Silpo.UA'),
            passwordHash: 'hash-1',
            role: $role,
            storeIds: $storeIds,
            now: $this->now,
            fullName: 'Іван Петренко',
        );
    }

    /**
     * AUTH-10: email нормалізується до нижнього регістру, обрізаються пробіли.
     */
    public function testEmailIsNormalizedToLowercase(): void
    {
        $user = $this->user();

        self::assertSame('ivan.petrenko@silpo.ua', $user->email()->value);
        self::assertSame('ivan.petrenko', $user->email()->localPart());
        self::assertSame('ivan.petrenko@silpo.ua', Email::fromString('  IVAN.PETRENKO@SILPO.UA  ')->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'порожній' => [''],
            'лише пробіли' => ['   '],
            'без @' => ['ivan.silpo.ua'],
            'без домену' => ['ivan@'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testInvalidEmailIsRejected(string $raw): void
    {
        $this->expectException(ValidationException::class);
        Email::fromString($raw);
    }

    /**
     * RBAC-27.1 / таблиця 4.10, сценарій 9: спроба призначити другу роль —
     * 422 RBAC_MULTIPLE_ROLES_FORBIDDEN.
     */
    public function testAssigningSecondRoleIsForbidden(): void
    {
        $user = $this->user(Role::StoreOperator);

        try {
            $user->assignRoles([Role::StoreOperator, Role::StoreManager], $this->now);
            self::fail('Очікувалася відмова RBAC_MULTIPLE_ROLES_FORBIDDEN.');
        } catch (MultipleRolesForbiddenException $exception) {
            self::assertSame('RBAC_MULTIPLE_ROLES_FORBIDDEN', $exception->errorCode());
            self::assertSame(422, $exception->httpStatus());
            self::assertSame('Користувач може мати лише одну роль', $exception->userMessage());
            self::assertSame(['store_operator', 'store_manager'], $exception->context()['requestedRoles']);
        }

        // Стан не змінився — часткового застосування немає
        self::assertSame(Role::StoreOperator, $user->role());
    }

    public function testAssigningEmptyRoleListIsForbidden(): void
    {
        $this->expectException(MultipleRolesForbiddenException::class);
        $this->user()->assignRoles([], $this->now);
    }

    public function testAssigningExactlyOneRoleSucceeds(): void
    {
        $user = $this->user(Role::StoreOperator);
        $user->assignRoles([Role::StoreManager], $this->now);

        self::assertSame(Role::StoreManager, $user->role());
    }

    /**
     * RBAC-27.2/RBAC-27.4: акаунт staff-контуру не може отримати partner-роль.
     */
    #[DataProvider('partnerRoleProvider')]
    public function testPartnerRoleCannotBeAssignedToStaffAccount(Role $partnerRole): void
    {
        $user = $this->user();

        try {
            $user->changeRole($partnerRole, $this->now);
            self::fail('Очікувалася відмова через крос-контурну роль.');
        } catch (CrossContourRoleException $exception) {
            self::assertSame('RBAC_CROSS_CONTOUR_ROLE_FORBIDDEN', $exception->errorCode());
            self::assertSame('partner', $exception->context()['contour']);
        }

        self::assertSame(Role::StoreManager, $user->role());
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function partnerRoleProvider(): array
    {
        return [
            'supplier_admin' => [Role::SupplierAdmin],
            'supplier_operator' => [Role::SupplierOperator],
            'driver' => [Role::Driver],
        ];
    }

    public function testStaffUserCannotBeCreatedWithPartnerRole(): void
    {
        $this->expectException(CrossContourRoleException::class);

        StaffUser::create(
            email: Email::fromString('driver@partner.ua'),
            passwordHash: 'hash',
            role: Role::Driver,
            storeIds: [],
            now: $this->now,
        );
    }

    /**
     * RBAC-13/DATA-36: порожній storeIds магазинної ролі — нуль доступу.
     */
    public function testEmptyStoreScopeMeansNoStores(): void
    {
        $user = $this->user(Role::StoreManager, []);

        self::assertSame([], $user->storeIds());
        self::assertFalse($user->hasStoreInScope('A'));
        self::assertFalse($user->isNetworkWide());
    }

    public function testNetworkRoleSeesEveryStoreRegardlessOfStoreIds(): void
    {
        $user = $this->user(Role::NetworkManager, []);

        self::assertTrue($user->isNetworkWide());
        self::assertTrue($user->hasStoreInScope('будь-який'));
    }

    public function testStoreIdsAreDeduplicatedAndValidated(): void
    {
        $user = $this->user(Role::StoreManager, ['A', 'B', 'A']);
        self::assertSame(['A', 'B'], $user->storeIds());

        $this->expectException(ValidationException::class);
        $user->changeScope(['A', '  '], $this->now);
    }

    /**
     * AUTH-13: історія паролів обмежена 5 записами, найновіший — першим.
     */
    public function testPasswordHistoryKeepsLastFive(): void
    {
        $user = $this->user();

        foreach (['h2', 'h3', 'h4', 'h5', 'h6', 'h7'] as $index => $hash) {
            $user->changePassword($hash, $this->now->modify(\sprintf('+%d minutes', $index + 1)));
        }

        self::assertSame('h7', $user->passwordHash());
        self::assertSame(['h6', 'h5', 'h4', 'h3', 'h2'], $user->passwordHistory());
        self::assertCount(StaffUser::PASSWORD_HISTORY_SIZE, $user->passwordHistory());
    }

    /**
     * AUTH-12 і DATA-03: деактивація та soft delete через archivedAt.
     */
    public function testDeactivationAndArchiving(): void
    {
        $user = $this->user();
        self::assertTrue($user->isActive());

        $user->deactivate($this->now);
        self::assertFalse($user->isActive());

        $user->activate($this->now);
        self::assertTrue($user->isActive());

        $user->archive($this->now);
        self::assertFalse($user->isActive());
        self::assertEquals($this->now, $user->archivedAt());
    }

    /**
     * DATA-05: ідентифікатор — UUID v4; DATA-02: документ має schemaVersion.
     */
    public function testIdentifierIsUuidV4AndDocumentIsVersioned(): void
    {
        $user = $this->user();

        self::assertTrue(Uuid::isValid($user->id()));
        self::assertSame(StaffUser::SCHEMA_VERSION, $user->schemaVersion());
        self::assertNull($user->lastLoginAt());

        $user->registerSuccessfulLogin($this->now);
        self::assertEquals($this->now, $user->lastLoginAt());
    }
}
