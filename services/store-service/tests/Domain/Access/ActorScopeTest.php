<?php

declare(strict_types=1);

namespace App\Tests\Domain\Access;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Contour;
use App\Domain\Access\Role;
use App\Domain\Shared\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Правило скоупу RBAC-13 у чистому вигляді: для store_manager і store_operator
 * ПОРОЖНІЙ перелік магазинів означає НУЛЬ доступу, а не «усі магазини».
 * Скоуп «вся мережа» дає РОЛЬ (RBAC-16), а не порожній перелік.
 */
#[CoversClass(Actor::class)]
#[CoversClass(Role::class)]
#[CoversClass(AccessDeniedException::class)]
final class ActorScopeTest extends TestCase
{
    private const string STORE_A = 'S-01';
    private const string STORE_B = 'S-02';
    private const string STORE_C = 'S-03';

    /** RBAC-13: магазинна роль без магазинів не має доступу до ЖОДНОГО магазину. */
    #[DataProvider('storeRoleProvider')]
    public function testStoreRoleWithEmptyScopeHasZeroAccess(Role $role): void
    {
        $actor = self::staff($role, []);

        self::assertSame([], $actor->storeScope(), 'порожній перелік лишається предикатом, а не null');
        self::assertFalse($actor->canAccessStore(self::STORE_A));
        self::assertFalse($actor->canAccessStore(self::STORE_B));
        self::assertFalse($actor->canAccessStore(self::STORE_C));
    }

    /** RBAC-13: доступ рівно до магазинів переліку — і до жодного іншого. */
    #[DataProvider('storeRoleProvider')]
    public function testStoreRoleIsLimitedToItsOwnStores(Role $role): void
    {
        $actor = self::staff($role, [self::STORE_A, self::STORE_B]);

        self::assertSame([self::STORE_A, self::STORE_B], $actor->storeScope());
        self::assertTrue($actor->canAccessStore(self::STORE_A));
        self::assertTrue($actor->canAccessStore(self::STORE_B));
        self::assertFalse($actor->canAccessStore(self::STORE_C));
    }

    /** RBAC-16: мережеві ролі не фільтруються за переліком магазинів. */
    #[DataProvider('networkRoleProvider')]
    public function testNetworkRoleReachesEveryStore(Role $role): void
    {
        $actor = self::staff($role, []);

        self::assertNull($actor->storeScope(), 'мережевій ролі предикат за storeIds не потрібен');
        self::assertTrue($actor->canAccessStore(self::STORE_A));
        self::assertTrue($actor->canAccessStore(self::STORE_C));
    }

    /**
     * Порожній перелік у мережевої ролі — це «не застосовно», у магазинної —
     * нуль доступу. Один і той самий заголовок, різне трактування за роллю.
     */
    public function testEmptyScopeMeansOppositeThingsForNetworkAndStoreRoles(): void
    {
        $networkManager = self::staff(Role::NetworkManager, []);
        $storeManager = self::staff(Role::StoreManager, []);

        self::assertTrue($networkManager->canAccessStore(self::STORE_A));
        self::assertFalse($storeManager->canAccessStore(self::STORE_A));
    }

    /** RBAC-14: скоуп постачальника задає supplierId; перелік магазинів лише звужує (SUP-03). */
    public function testSupplierScopeIsDrivenBySupplierIdAndNarrowedByStoreIds(): void
    {
        $withoutList = self::supplier([]);
        $withList = self::supplier([self::STORE_A]);

        self::assertNull($withoutList->storeScope());
        self::assertTrue($withoutList->canAccessStore(self::STORE_C));

        self::assertSame([self::STORE_A], $withList->storeScope());
        self::assertTrue($withList->canAccessStore(self::STORE_A));
        self::assertFalse($withList->canAccessStore(self::STORE_C));
    }

    /** RBAC-18: читання магазину поза скоупом — 404, існування не розкривається. */
    public function testReadOutsideScopeIsNotFound(): void
    {
        $actor = self::staff(Role::StoreOperator, [self::STORE_A]);

        try {
            $actor->assertCanReadStore(self::STORE_C);
            self::fail('очікувався NotFoundException');
        } catch (NotFoundException $exception) {
            self::assertSame(404, $exception->httpStatus());
            self::assertSame('STORE_NOT_FOUND', $exception->errorCode());
        }
    }

    /** RBAC-18: дія над магазином поза скоупом — 403 RBAC_SCOPE_VIOLATION. */
    public function testActionOutsideScopeIsScopeViolation(): void
    {
        $actor = self::staff(Role::StoreManager, [self::STORE_A]);

        try {
            $actor->assertCanActOnStore(self::STORE_C);
            self::fail('очікувався AccessDeniedException');
        } catch (AccessDeniedException $exception) {
            self::assertSame(403, $exception->httpStatus());
            self::assertSame('RBAC_SCOPE_VIOLATION', $exception->errorCode());
        }
    }

    public function testActionInsideScopePasses(): void
    {
        $actor = self::staff(Role::StoreManager, [self::STORE_A]);

        $actor->assertCanReadStore(self::STORE_A);
        $actor->assertCanActOnStore(self::STORE_A);

        self::assertTrue($actor->canAccessStore(self::STORE_A));
    }

    /** @return iterable<string, array{Role}> */
    public static function storeRoleProvider(): iterable
    {
        yield 'store_manager' => [Role::StoreManager];
        yield 'store_operator' => [Role::StoreOperator];
    }

    /** @return iterable<string, array{Role}> */
    public static function networkRoleProvider(): iterable
    {
        yield 'super_admin' => [Role::SuperAdmin];
        yield 'network_manager' => [Role::NetworkManager];
        yield 'analyst' => [Role::Analyst];
    }

    /** @param list<string> $storeIds */
    private static function staff(Role $role, array $storeIds): Actor
    {
        return new Actor('staff-1', $role, null, $storeIds, Contour::Staff);
    }

    /** @param list<string> $storeIds */
    private static function supplier(array $storeIds): Actor
    {
        return new Actor('partner-1', Role::SupplierOperator, 'supplier-1', $storeIds, Contour::Partner);
    }
}
