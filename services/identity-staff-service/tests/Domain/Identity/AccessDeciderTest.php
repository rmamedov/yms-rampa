<?php

declare(strict_types=1);

namespace App\Tests\Domain\Identity;

use App\Domain\Identity\AccessDecider;
use App\Domain\Identity\Email;
use App\Domain\Identity\Exception\PermissionDeniedException;
use App\Domain\Identity\Exception\ScopeViolationException;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Скоупінг даних 4.5 (RBAC-13, RBAC-16, RBAC-17, RBAC-18)
 * і критерій приймання RBAC-AC-08 (порожній storeIds = нуль доступу).
 */
#[CoversClass(AccessDecider::class)]
final class AccessDeciderTest extends TestCase
{
    private AccessDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new AccessDecider();
    }

    /**
     * @param list<string> $storeIds
     */
    private function user(Role $role, array $storeIds = [], bool $active = true): StaffUser
    {
        $now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');

        $user = StaffUser::create(
            email: Email::fromString('user@silpo.ua'),
            passwordHash: 'hash',
            role: $role,
            storeIds: $storeIds,
            now: $now,
        );

        if (!$active) {
            $user->deactivate($now);
        }

        return $user;
    }

    /**
     * @return array<string, array{Role, list<string>, Permission, string|null, bool}>
     */
    public static function scopeProvider(): array
    {
        return [
            // RBAC-13: store_manager бачить лише свої магазини
            'store_manager у своєму магазині' => [Role::StoreManager, ['A', 'B'], Permission::SlotBlock, 'A', true],
            'store_manager у другому своєму магазині' => [Role::StoreManager, ['A', 'B'], Permission::SlotBlock, 'B', true],
            'store_manager у чужому магазині' => [Role::StoreManager, ['A', 'B'], Permission::SlotBlock, 'C', false],
            'store_operator у своєму магазині' => [Role::StoreOperator, ['A'], Permission::BookingMarkUnloaded, 'A', true],
            'store_operator у чужому магазині' => [Role::StoreOperator, ['A'], Permission::BookingMarkUnloaded, 'B', false],

            // RBAC-AC-08: порожній масив — НУЛЬ доступу, а не вся мережа
            'store_manager з порожнім storeIds — конкретний магазин' => [Role::StoreManager, [], Permission::BookingReadAll, 'A', false],
            'store_manager з порожнім storeIds — без магазину' => [Role::StoreManager, [], Permission::BookingReadAll, null, false],
            'store_operator з порожнім storeIds' => [Role::StoreOperator, [], Permission::BookingMarkUnloaded, 'A', false],

            // RBAC-16: мережеві ролі не фільтруються за storeIds
            'super_admin у будь-якому магазині' => [Role::SuperAdmin, [], Permission::BookingMarkUnloaded, 'Z', true],
            'network_manager у будь-якому магазині' => [Role::NetworkManager, [], Permission::StoreConfigure, 'Z', true],
            'analyst читає по всій мережі' => [Role::Analyst, [], Permission::BookingReadAll, 'Z', true],

            // Матриця 4.4: право відсутнє незалежно від скоупа
            'analyst не блокує слоти' => [Role::Analyst, [], Permission::SlotBlock, 'A', false],
            'store_operator не блокує слоти' => [Role::StoreOperator, ['A'], Permission::SlotBlock, 'A', false],
            'network_manager не відмічає розвантаження' => [Role::NetworkManager, [], Permission::BookingMarkUnloaded, 'A', false],
            'store_manager не налаштовує магазин' => [Role::StoreManager, ['A'], Permission::StoreConfigure, 'A', false],
            'store_manager не резервує слоти' => [Role::StoreManager, ['A'], Permission::SlotReserve, 'A', false],
            'store_operator не скасовує чужі бронювання' => [Role::StoreOperator, ['A'], Permission::BookingCancelAny, 'A', false],
            'store_operator без аналітики' => [Role::StoreOperator, ['A'], Permission::AnalyticsView, 'A', false],
            'store_manager з аналітикою свого магазину' => [Role::StoreManager, ['A'], Permission::AnalyticsView, 'A', true],
        ];
    }

    /**
     * @param list<string> $storeIds
     */
    #[DataProvider('scopeProvider')]
    public function testCanRespectsMatrixAndScope(
        Role $role,
        array $storeIds,
        Permission $permission,
        ?string $storeId,
        bool $expected,
    ): void {
        self::assertSame($expected, $this->decider->can($this->user($role, $storeIds), $permission, $storeId));
    }

    /**
     * RBAC-AC-08: порожній storeIds не інтерпретується як «вся мережа».
     */
    public function testEmptyStoreIdsIsZeroAccessNotWholeNetwork(): void
    {
        $scoped = $this->user(Role::StoreManager, []);
        $networkWide = $this->user(Role::Analyst, []);

        // Обидва мають порожній storeIds, але тільки мережева роль бачить мережу
        self::assertFalse($this->decider->can($scoped, Permission::BookingReadAll, 'A'));
        self::assertTrue($this->decider->can($networkWide, Permission::BookingReadAll, 'A'));

        // RBAC-17: предикат запиту для магазинної ролі — порожній список (порожня вибірка),
        // для мережевої — відсутність фільтра
        self::assertSame([], $this->decider->storeScopeFilter($scoped));
        self::assertNull($this->decider->storeScopeFilter($networkWide));
    }

    /**
     * Таблиця 4.10, сценарій 4: дія поза скоупом — 403 RBAC_SCOPE_VIOLATION.
     */
    public function testActionOutsideScopeThrowsScopeViolation(): void
    {
        $user = $this->user(Role::StoreOperator, ['A']);

        $decision = $this->decider->decide($user, Permission::BookingMarkUnloaded, 'B');
        self::assertFalse($decision->allowed);
        self::assertSame('RBAC_SCOPE_VIOLATION', $decision->errorCode);

        $this->expectException(ScopeViolationException::class);
        $this->decider->assertCan($user, Permission::BookingMarkUnloaded, 'B');
    }

    /**
     * Таблиця 4.10, сценарій 3: роль без права — 403 RBAC_PERMISSION_DENIED.
     */
    public function testMissingPermissionThrowsPermissionDenied(): void
    {
        $analyst = $this->user(Role::Analyst);

        $decision = $this->decider->decide($analyst, Permission::SlotBlock, 'A');
        self::assertFalse($decision->allowed);
        self::assertSame('RBAC_PERMISSION_DENIED', $decision->errorCode);

        $this->expectException(PermissionDeniedException::class);
        $this->decider->assertCan($analyst, Permission::SlotBlock, 'A');
    }

    /**
     * AUTH-12: деактивований акаунт не має жодного доступу навіть із роллю super_admin.
     */
    public function testDeactivatedUserHasNoAccessAtAll(): void
    {
        $user = $this->user(Role::SuperAdmin, [], active: false);

        self::assertFalse($this->decider->can($user, Permission::BookingReadAll));
        self::assertSame('AUTH_ACCOUNT_DISABLED', $this->decider->decide($user, Permission::BookingReadAll)->errorCode);
    }

    /**
     * RBAC-13: додавання магазину C розширює видимість (сценарій RBAC-AC-04).
     */
    public function testScopeChangeExtendsVisibility(): void
    {
        $user = $this->user(Role::StoreManager, ['A', 'B']);
        self::assertFalse($this->decider->can($user, Permission::BookingReadAll, 'C'));

        $user->changeScope(['A', 'B', 'C'], new \DateTimeImmutable('2026-08-27T10:00:00+00:00'));

        self::assertTrue($this->decider->can($user, Permission::BookingReadAll, 'C'));
        self::assertSame(['A', 'B', 'C'], $this->decider->storeScopeFilter($user));
    }
}
