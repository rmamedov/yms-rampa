<?php

declare(strict_types=1);

namespace App\Tests\Domain\Access;

use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Exception\TransitionNotAllowedException;
use App\Tests\Support\Scenario;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RBAC-13: скоуп магазинів актора.
 *
 * ПОРОЖНІЙ перелік магазинів для ролей store_manager / store_operator означає
 * НУЛЬ ДОСТУПУ — жодного магазину. Скоуп «уся мережа» дає РОЛЬ
 * (super_admin, network_manager), а не відсутність обмежень.
 */
#[CoversClass(Actor::class)]
final class ActorScopeTest extends TestCase
{
    /** RBAC-13, головний негативний випадок: порожньо ≠ «будь-який магазин». */
    #[DataProvider('storeRoles')]
    public function testStoreRoleWithEmptyScopeCannotOperateAnyStore(Role $role): void
    {
        $actor = new Actor('su-1', $role, storeIds: []);

        self::assertSame([], $actor->storeIds);

        foreach (['store-1', 'store-2', Scenario::STORE_ID, 'S-01'] as $storeId) {
            self::assertFalse(
                $actor->canOperateStore($storeId),
                \sprintf('%s без магазинів у скоупі не має доступу до «%s»', $role->value, $storeId),
            );
        }
    }

    /** Непорожній перелік — доступ рівно до магазинів зі списку. */
    #[DataProvider('storeRoles')]
    public function testStoreRoleOperatesOnlyStoresFromItsScope(Role $role): void
    {
        $actor = new Actor('su-2', $role, storeIds: ['S-01', 'S-02']);

        self::assertTrue($actor->canOperateStore('S-01'));
        self::assertTrue($actor->canOperateStore('S-02'));
        self::assertFalse($actor->canOperateStore('S-03'));
        self::assertFalse($actor->canOperateStore(''));
    }

    /** Мережеві ролі працюють у будь-якій філії — це дає роль, а не перелік. */
    #[DataProvider('networkRoles')]
    public function testNetworkRoleOperatesAnyStore(Role $role): void
    {
        $actor = new Actor('ad-1', $role);

        self::assertTrue($actor->canOperateStore('S-01'));
        self::assertTrue($actor->canOperateStore('S-99'));
        self::assertTrue($actor->canOperateStore(Scenario::STORE_ID));
    }

    /**
     * Аналітик має мережевий скоуп на читання, але операційних повноважень
     * магазину (переходи статусів, walk-in) не має.
     */
    public function testAnalystHasNoOperationalStorePowers(): void
    {
        self::assertFalse((new Actor('an-1', Role::Analyst))->canOperateStore(Scenario::STORE_ID));
        self::assertFalse(
            (new Actor('an-1', Role::Analyst, storeIds: [Scenario::STORE_ID]))->canOperateStore(Scenario::STORE_ID),
        );
    }

    /** Контур partner не отримує повноважень магазину навіть із заповненим скоупом. */
    public function testPartnerRolesNeverOperateStores(): void
    {
        $supplier = new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID, storeIds: [Scenario::STORE_ID]);
        $driver = new Actor('acc-du-1', Role::Driver, storeIds: [Scenario::STORE_ID], driverProfileId: 'du-1');

        self::assertFalse($supplier->canOperateStore(Scenario::STORE_ID));
        self::assertFalse($driver->canOperateStore(Scenario::STORE_ID));
    }

    /** DRV: водій діє лише щодо точок, закріплених саме за його ПРОФІЛЕМ. */
    public function testDriverActsOnlyOnOwnRouteSheetPoints(): void
    {
        $driver = new Actor('acc-du-1', Role::Driver, driverProfileId: 'du-1');

        self::assertTrue($driver->canActOnOwnRouteSheet('du-1'));
        self::assertFalse($driver->canActOnOwnRouteSheet('du-2'));
        self::assertFalse($driver->canActOnOwnRouteSheet(null));
        self::assertFalse($driver->canActOnOwnRouteSheet(''));
        self::assertFalse($driver->canActOnOwnRouteSheet('  '));
    }

    /**
     * DRV, головна регресія: обліковий запис із клейму `sub` НЕ є
     * підставою для доступу. Бронювання, driverId якого випадково збігся
     * з обліковим записом, водієві все одно не належить — належність
     * визначає лише профіль.
     */
    public function testAccountIdIsNeverAcceptedInsteadOfDriverProfile(): void
    {
        $driver = new Actor('acc-du-1', Role::Driver, driverProfileId: 'du-1');

        self::assertNotSame($driver->userId, $driver->driverProfileId);
        self::assertFalse($driver->canActOnOwnRouteSheet('acc-du-1'));
        self::assertFalse($driver->canActOnOwnRouteSheet($driver->userId));
        self::assertTrue($driver->canActOnOwnRouteSheet($driver->driverProfileId));
    }

    /**
     * DRV: водій без привʼязаного профілю (порожній X-Driver-Profile-Id)
     * не має доступу НІ ДО ЧОГО — порожнє значення не «підходить будь-чому»
     * і не відкочується на порівняння з обліковим записом.
     */
    #[DataProvider('emptyDriverProfileIds')]
    public function testDriverWithoutProfileHasZeroRouteSheetAccess(?string $driverProfileId): void
    {
        $driver = new Actor('acc-du-1', Role::Driver, driverProfileId: $driverProfileId);

        self::assertNull($driver->driverProfileId);
        self::assertFalse($driver->hasDriverProfile());

        foreach (['du-1', 'du-2', 'acc-du-1', '', null] as $bookingDriverId) {
            self::assertFalse(
                $driver->canActOnOwnRouteSheet($bookingDriverId),
                'Водій без профілю не має доступу до жодного бронювання',
            );
        }
    }

    /**
     * Перевірка маршрутного листа — ДОДАТКОВА, а не альтернативна: жодна інша
     * роль (включно з системним актором) не отримує через неї повноважень.
     */
    #[DataProvider('nonDriverActors')]
    public function testOnlyDriverRoleCanActOnRouteSheetPoints(Actor $actor): void
    {
        self::assertFalse($actor->hasDriverProfile());
        self::assertFalse($actor->canActOnOwnRouteSheet($actor->userId));
        self::assertFalse($actor->canActOnOwnRouteSheet('du-1'));
    }

    /**
     * Профіль водія у не-водія не додає повноважень: роль лишається
     * єдиним ключем до контуру.
     */
    #[DataProvider('nonDriverActorsWithDriverProfile')]
    public function testDriverProfileGrantsNothingToNonDriverRoles(Actor $actor): void
    {
        self::assertFalse($actor->hasDriverProfile());
        self::assertFalse($actor->canActOnOwnRouteSheet('du-1'));
    }

    /** Повноважень магазину перевірка маршрутного листа водієві не додає. */
    public function testRouteSheetOwnershipGrantsNoStorePowers(): void
    {
        $driver = new Actor('acc-du-1', Role::Driver, storeIds: [Scenario::STORE_ID], driverProfileId: 'du-1');

        self::assertTrue($driver->canActOnOwnRouteSheet('du-1'));
        self::assertFalse($driver->canOperateStore(Scenario::STORE_ID));
    }

    /** Профіль нормалізується так само, як решта ідентифікаторів. */
    public function testDriverProfileIdIsTrimmed(): void
    {
        $driver = new Actor('acc-du-1', Role::Driver, driverProfileId: '  du-1  ');

        self::assertSame('du-1', $driver->driverProfileId);
        self::assertTrue($driver->canActOnOwnRouteSheet('du-1'));
        self::assertFalse($driver->canActOnOwnRouteSheet('  du-1  '));
    }

    /** NOSH-01: системний актор cron працює в будь-якій філії. */
    public function testSystemActorOperatesAnyStore(): void
    {
        self::assertTrue(Actor::system()->canOperateStore('S-77'));
    }

    /** Порожні елементи, дублікати й пробіли з переліку відкидаються. */
    public function testStoreScopeIsNormalized(): void
    {
        $actor = new Actor('su-3', Role::StoreOperator, storeIds: [' S-01 ', 'S-01', '', '  ', 'S-02']);

        self::assertSame(['S-01', 'S-02'], $actor->storeIds);
        self::assertTrue($actor->canOperateStore('S-01'));
        self::assertTrue($actor->canOperateStore('S-02'));
        self::assertFalse($actor->canOperateStore(' S-01 '));
    }

    /** Скоуп із самих лише порожніх значень = порожній скоуп = нуль доступу. */
    public function testScopeOfBlankValuesIsZeroAccess(): void
    {
        $actor = new Actor('su-4', Role::StoreManager, storeIds: ['', ' ']);

        self::assertSame([], $actor->storeIds);
        self::assertFalse($actor->canOperateStore('S-01'));
    }

    /** Роль постачальника без supplierId неприпустима навіть у домені. */
    #[DataProvider('emptySupplierIds')]
    public function testSupplierRoleWithoutSupplierIdIsRejected(?string $supplierId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Actor('pu-1', Role::SupplierOperator, supplierId: $supplierId);
    }

    /** Постачальник діє лише від імені свого supplierId. */
    public function testSupplierActsOnlyForOwnSupplier(): void
    {
        $actor = new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID);

        self::assertTrue($actor->belongsToSupplier(Scenario::SUPPLIER_ID));
        self::assertFalse($actor->belongsToSupplier(Scenario::OTHER_SUPPLIER_ID));
        self::assertFalse($actor->belongsToSupplier(''));
    }

    /**
     * Наскрізний ефект правила: магазинна роль поза скоупом не може вести
     * бронювання чужої філії (ST-01).
     */
    public function testStoreStaffOutsideScopeCannotDriveBookingLifecycle(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $foreign = $scenario->storeStaff(storeIds: ['store-9']);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->markArrived($foreign, $booking->id, Scenario::kyiv('2026-08-28 09:58'));
    }

    /** Той самий сценарій із порожнім скоупом — головна регресія RBAC-13. */
    public function testStoreStaffWithEmptyScopeCannotDriveBookingLifecycle(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $unscoped = $scenario->storeStaff(storeIds: []);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->markArrived($unscoped, $booking->id, Scenario::kyiv('2026-08-28 09:58'));
    }

    /** Магазин зі своєї філії — перехід відбувається. */
    public function testStoreStaffInsideScopeDrivesBookingLifecycle(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $staff = $scenario->storeStaff(storeIds: ['store-9', Scenario::STORE_ID]);

        $arrived = $scenario->lifecycle->markArrived($staff, $booking->id, Scenario::kyiv('2026-08-28 09:58'));

        self::assertSame('arrived', $arrived->status()->value);
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function storeRoles(): iterable
    {
        yield 'store_manager' => [Role::StoreManager];
        yield 'store_operator' => [Role::StoreOperator];
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function networkRoles(): iterable
    {
        yield 'super_admin' => [Role::SuperAdmin];
        yield 'network_manager' => [Role::NetworkManager];
    }

    /**
     * @return iterable<string, array{Actor}>
     */
    public static function nonDriverActors(): iterable
    {
        yield 'store_manager' => [new Actor('su-1', Role::StoreManager, storeIds: [Scenario::STORE_ID])];
        yield 'store_operator' => [new Actor('su-2', Role::StoreOperator, storeIds: [Scenario::STORE_ID])];
        yield 'network_manager' => [new Actor('ad-1', Role::NetworkManager)];
        yield 'super_admin' => [new Actor('ad-2', Role::SuperAdmin)];
        yield 'analyst' => [new Actor('an-1', Role::Analyst)];
        yield 'supplier_admin' => [new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID)];
        yield 'supplier_operator' => [new Actor('pu-2', Role::SupplierOperator, supplierId: Scenario::SUPPLIER_ID)];
        yield 'system' => [Actor::system()];
    }

    /**
     * @return iterable<string, array{Actor}>
     */
    public static function nonDriverActorsWithDriverProfile(): iterable
    {
        yield 'store_manager' => [new Actor('su-1', Role::StoreManager, storeIds: [Scenario::STORE_ID], driverProfileId: 'du-1')];
        yield 'network_manager' => [new Actor('ad-1', Role::NetworkManager, driverProfileId: 'du-1')];
        yield 'analyst' => [new Actor('an-1', Role::Analyst, driverProfileId: 'du-1')];
        yield 'supplier_admin' => [new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID, driverProfileId: 'du-1')];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function emptySupplierIds(): iterable
    {
        yield 'null' => [null];
        yield 'порожній рядок' => [''];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function emptyDriverProfileIds(): iterable
    {
        yield 'null' => [null];
        yield 'порожній рядок' => [''];
        yield 'самі пробіли' => ['   '];
    }
}
