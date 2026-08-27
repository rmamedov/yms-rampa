<?php

declare(strict_types=1);

namespace App\Tests\Domain\Access;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Contour;
use App\Domain\Access\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Правило скоупу RBAC-13/RBAC-16 у чистому вигляді, без HTTP.
 *
 * Для store_manager і store_operator перелік магазинів вичерпний: порожній
 * означає нуль доступу. Скоуп «уся мережа» дає роль, а не перелік.
 */
#[CoversClass(Actor::class)]
#[CoversClass(Role::class)]
#[CoversClass(AccessDeniedException::class)]
final class ActorTest extends TestCase
{
    #[Test]
    public function storeRoleWithEmptyScopeReachesNoStoreAtAll(): void
    {
        $actor = new Actor('u-1', Role::StoreManager, Contour::Staff, storeIds: []);

        self::assertTrue($actor->canReadAnalytics());
        self::assertFalse($actor->canAccessStore('S-01'));
        self::assertFalse($actor->canAccessStore('S-02'));

        $this->expectException(AccessDeniedException::class);
        $actor->narrowStoreScope([]);
    }

    #[Test]
    public function storeRoleReachesExactlyItsOwnStores(): void
    {
        $actor = new Actor('u-1', Role::StoreOperator, Contour::Staff, storeIds: ['S-01', 'S-02']);

        self::assertTrue($actor->canAccessStore('S-01'));
        self::assertTrue($actor->canAccessStore('S-02'));
        self::assertFalse($actor->canAccessStore('S-03'));

        self::assertSame(['S-01', 'S-02'], $actor->narrowStoreScope([]));
        self::assertSame(['S-01'], $actor->narrowStoreScope(['S-01']));
    }

    #[Test]
    public function storeRoleIsRefusedForeignStoreInsteadOfSilentlyDroppingIt(): void
    {
        $actor = new Actor('u-1', Role::StoreManager, Contour::Staff, storeIds: ['S-01', 'S-02']);

        try {
            $actor->narrowStoreScope(['S-03']);
            self::fail('Очікувалася відмова для магазину поза скоупом.');
        } catch (AccessDeniedException $exception) {
            self::assertStringContainsString('S-03', $exception->getMessage());
            self::assertSame(403, $exception->httpStatus());
        }
    }

    #[Test]
    public function networkRolesReachAnyStoreWithoutStoreList(): void
    {
        foreach ([Role::SuperAdmin, Role::NetworkManager, Role::Analyst] as $role) {
            $actor = new Actor('u-net', $role, Contour::Staff);

            self::assertTrue($actor->canAccessStore('S-77'), $role->value);
            // Порожньо = без обмеження за магазином саме тому, що роль мережева.
            self::assertSame([], $actor->narrowStoreScope([]), $role->value);
            self::assertSame(['S-77'], $actor->narrowStoreScope(['S-77']), $role->value);
        }
    }

    #[Test]
    public function partnerRolesCannotReadAnalyticsEvenWithStoreList(): void
    {
        $actor = new Actor('u-sup', Role::SupplierAdmin, Contour::Partner, supplierId: 'sup-1', storeIds: ['S-01']);

        self::assertFalse($actor->canReadAnalytics());
        self::assertFalse($actor->canAccessStore('S-01'));

        $this->expectException(AccessDeniedException::class);
        $actor->narrowStoreScope(['S-01']);
    }

    #[Test]
    public function driverCannotReadAnalytics(): void
    {
        $actor = new Actor('u-drv', Role::Driver, Contour::Partner);

        self::assertFalse($actor->canReadAnalytics());
    }

    #[Test]
    public function supplierRoleRequiresSupplierId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Actor('u-sup', Role::SupplierOperator, Contour::Partner, supplierId: '');
    }

    #[Test]
    public function eachRoleBelongsToExactlyOneContour(): void
    {
        self::assertSame(Contour::Staff, Role::SuperAdmin->contour());
        self::assertSame(Contour::Staff, Role::NetworkManager->contour());
        self::assertSame(Contour::Staff, Role::Analyst->contour());
        self::assertSame(Contour::Staff, Role::StoreManager->contour());
        self::assertSame(Contour::Staff, Role::StoreOperator->contour());
        self::assertSame(Contour::Partner, Role::SupplierAdmin->contour());
        self::assertSame(Contour::Partner, Role::SupplierOperator->contour());
        self::assertSame(Contour::Partner, Role::Driver->contour());
    }
}
