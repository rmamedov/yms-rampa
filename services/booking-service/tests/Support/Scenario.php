<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Booking\BookingCreationService;
use App\Application\Booking\BookingLifecycleService;
use App\Application\Booking\DriverBookingService;
use App\Application\Booking\NewBookingRequest;
use App\Application\Booking\NoShowSweeper;
use App\Application\Booking\WalkInRequest;
use App\Application\Hold\SlotHoldService;
use App\Application\RouteSheet\RouteSheetService;
use App\Application\Slot\SlotGridService;
use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\StoreSnapshot;
use App\Domain\Booking\VehicleSnapshot;
use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\SlotGridGenerator;
use App\Domain\Slot\SlotKey;
use App\Domain\Slot\StoreConfig;
use App\Domain\Slot\TimeInterval;
use App\Domain\Store\StorePolicy;
use App\Domain\Store\StoreSettings;
use App\Domain\Supplier\SupplierInfo;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryBookingRepository;
use App\Infrastructure\InMemory\InMemoryOutboxStore;
use App\Infrastructure\InMemory\InMemoryRouteSheetRepository;
use App\Infrastructure\InMemory\InMemorySlotHoldStore;
use App\Infrastructure\InMemory\InMemorySlotOverlayProvider;
use App\Infrastructure\InMemory\InMemorySupplierDirectory;
use App\Infrastructure\InMemory\SequentialIdGenerator;
use App\Infrastructure\Store\FixtureStoreConfigProvider;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Складене оточення booking-service повністю в памʼяті: MongoDB і Redis
 * для юніт-тестів не потрібні.
 *
 * Типовий магазин: вікно прийому 08:00–14:00 (пн–сб), слот 30 хв, дві рампи,
 * ліміт 20 т, lead time 60 хв, горизонт 14 днів, дедлайн змін 2 год,
 * grace no-show 30 хв — мережеві дефолти розділу 6.11.
 */
final class Scenario
{
    public const string STORE_ID = 'store-1';
    public const string SUPPLIER_ID = 'sp-1';
    public const string OTHER_SUPPLIER_ID = 'sp-2';

    public FrozenClock $clock;
    public FixtureStoreConfigProvider $stores;
    public InMemorySlotOverlayProvider $overlays;
    public InMemoryOutboxStore $outbox;
    public InMemoryBookingRepository $bookings;
    public InMemorySlotHoldStore $holds;
    public InMemoryRouteSheetRepository $routeSheetRepository;
    public InMemorySupplierDirectory $suppliers;
    public SlotGridService $grid;
    public SlotHoldService $holdService;
    public RouteSheetService $routeSheets;
    public BookingCreationService $creation;
    public BookingLifecycleService $lifecycle;
    public DriverBookingService $driverBookings;
    public NoShowSweeper $sweeper;

    public function __construct(string $now = '2026-08-27T06:00:00Z', ?StoreSettings $settings = null)
    {
        $this->clock = new FrozenClock($now);
        $this->stores = new FixtureStoreConfigProvider(strict: true);
        $this->stores->register($settings ?? self::storeSettings());

        $this->overlays = new InMemorySlotOverlayProvider();
        $this->outbox = new InMemoryOutboxStore();
        $this->bookings = new InMemoryBookingRepository($this->outbox);
        $this->holds = new InMemorySlotHoldStore();
        $this->routeSheetRepository = new InMemoryRouteSheetRepository();

        $this->suppliers = new InMemorySupplierDirectory([
            new SupplierInfo(self::SUPPLIER_ID, 'ТОВ Молокія'),
            new SupplierInfo(self::OTHER_SUPPLIER_ID, 'ТОВ Хлібзавод'),
        ]);

        $this->grid = new SlotGridService(
            $this->stores,
            $this->overlays,
            $this->bookings,
            $this->holds,
            new SlotGridGenerator(),
        );

        $this->routeSheets = new RouteSheetService(
            $this->routeSheetRepository,
            $this->bookings,
            new SequentialIdGenerator('rs-'),
        );

        $this->holdService = new SlotHoldService($this->grid, $this->holds);

        $this->creation = new BookingCreationService(
            $this->grid,
            $this->bookings,
            $this->holds,
            $this->suppliers,
            $this->routeSheets,
            new SequentialIdGenerator('bk-'),
        );

        $this->lifecycle = new BookingLifecycleService($this->bookings, $this->grid, $this->routeSheets);
        $this->driverBookings = new DriverBookingService($this->lifecycle, $this->bookings);
        $this->sweeper = new NoShowSweeper($this->bookings, $this->stores, $this->routeSheets);
    }

    public static function storeSettings(
        string $storeId = self::STORE_ID,
        float $maxVehicleWeightTons = 20.0,
        int $leadTimeMinutes = 60,
        int $bookingHorizonDays = 14,
        ?StorePolicy $policy = null,
        bool $ymsActive = true,
    ): StoreSettings {
        $windows = [];
        for ($day = 1; $day <= 6; ++$day) {
            $windows[] = new ReceivingWindow($day, [new TimeInterval('08:00', '14:00')]);
        }

        return new StoreSettings(
            config: new StoreConfig(
                storeId: $storeId,
                receivingWindows: $windows,
                slotSizeMinutes: 30,
                ramps: [new Ramp('r1', 'Рампа 1'), new Ramp('r2', 'Рампа 2')],
                maxVehicleWeightTons: $maxVehicleWeightTons,
                leadTimeMinutes: $leadTimeMinutes,
                bookingHorizonDays: $bookingHorizonDays,
            ),
            policy: $policy ?? new StorePolicy(),
            snapshot: new StoreSnapshot('1998', 'Сільпо Хрещатик', 'Київ', 'вул. Хрещатик, 12'),
            ymsActive: $ymsActive,
        );
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** Локальний час магазину «Y-m-d H:i» → момент в UTC. */
    public static function kyiv(string $localDateTime): DateTimeImmutable
    {
        return (new DateTimeImmutable($localDateTime, new DateTimeZone(StoreConfig::TIMEZONE)))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    public function supplier(string $supplierId = self::SUPPLIER_ID, Role $role = Role::SupplierAdmin): Actor
    {
        return new Actor('pu-'.$supplierId, $role, supplierId: $supplierId);
    }

    /**
     * Співробітник магазину зі скоупом магазинів (заголовок X-Store-Ids).
     *
     * @param list<string> $storeIds порожній перелік = нуль доступу (RBAC-13)
     */
    public function storeStaff(Role $role = Role::StoreManager, array $storeIds = [self::STORE_ID]): Actor
    {
        return new Actor('su-1', $role, storeIds: $storeIds);
    }

    /**
     * Водій контуру partner.
     *
     * DRV: обліковий запис (X-User-Id, клейм `sub`) і профіль водія
     * (X-Driver-Profile-Id) — РІЗНІ ідентифікатори, тому за замовчуванням
     * акаунт свідомо не дорівнює профілю: тести мають ловити будь-яку
     * спробу порівняти booking.driverId з обліковим записом.
     */
    public function driver(string $driverProfileId = 'du-1', ?string $userId = null): Actor
    {
        return new Actor(
            $userId ?? self::accountOf($driverProfileId),
            Role::Driver,
            driverProfileId: $driverProfileId,
        );
    }

    /** Водій, обліковий запис якого не привʼязаний до профілю — нуль доступу. */
    public function driverWithoutProfile(string $userId = 'acc-du-1'): Actor
    {
        return new Actor($userId, Role::Driver);
    }

    /** Обліковий запис identity-partner-service для профілю partner-service. */
    public static function accountOf(string $driverProfileId): string
    {
        return 'acc-'.$driverProfileId;
    }

    public function networkAdmin(): Actor
    {
        return new Actor('ad-1', Role::NetworkManager);
    }

    public static function vehicle(
        string $plateNumber = 'AA1234BB',
        float $weightTons = 5.0,
        ?string $brand = 'MAN',
    ): VehicleSnapshot {
        return new VehicleSnapshot($plateNumber, $weightTons, $brand);
    }

    public function request(
        string $localDateTime = '2026-08-28 10:00',
        string $rampId = 'r1',
        ?VehicleSnapshot $vehicle = null,
        int $palletsCount = 8,
        ?string $holdToken = null,
        bool $confirmConflict = false,
        ?string $driverId = null,
        ?string $orderId = null,
        string $storeId = self::STORE_ID,
    ): NewBookingRequest {
        return new NewBookingRequest(
            storeId: $storeId,
            rampId: $rampId,
            slotStart: self::kyiv($localDateTime),
            vehicle: $vehicle ?? self::vehicle(),
            palletsCount: $palletsCount,
            orderId: $orderId,
            driverId: $driverId,
            holdToken: $holdToken,
            confirmConflict: $confirmConflict,
        );
    }

    public function walkInRequest(
        string $localDateTime = '2026-08-27 09:00',
        string $rampId = 'r1',
        ?VehicleSnapshot $vehicle = null,
        int $palletsCount = 4,
        ?string $supplierId = self::SUPPLIER_ID,
        ?string $supplierName = null,
    ): WalkInRequest {
        return new WalkInRequest(
            storeId: self::STORE_ID,
            rampId: $rampId,
            slotStart: self::kyiv($localDateTime),
            vehicle: $vehicle ?? self::vehicle(),
            palletsCount: $palletsCount,
            supplierId: $supplierId,
            supplierName: $supplierName,
        );
    }

    /** Створити планове бронювання з дефолтними параметрами. */
    public function book(
        string $localDateTime = '2026-08-28 10:00',
        string $rampId = 'r1',
        ?VehicleSnapshot $vehicle = null,
        string $supplierId = self::SUPPLIER_ID,
        int $palletsCount = 8,
        bool $confirmConflict = false,
        ?string $driverId = null,
    ): Booking {
        return $this->creation->create(
            $this->supplier($supplierId),
            $this->request(
                localDateTime: $localDateTime,
                rampId: $rampId,
                vehicle: $vehicle,
                palletsCount: $palletsCount,
                confirmConflict: $confirmConflict,
                driverId: $driverId,
            ),
            $this->now(),
        );
    }

    public function slotKey(string $localDateTime = '2026-08-28 10:00', string $rampId = 'r1'): SlotKey
    {
        return new SlotKey(self::STORE_ID, $rampId, self::kyiv($localDateTime));
    }

    /** Перезавантажити агрегат зі сховища. */
    public function reload(Booking $booking): Booking
    {
        $reloaded = $this->bookings->find($booking->id);

        if (null === $reloaded) {
            throw new \RuntimeException('Бронювання зникло зі сховища: '.$booking->id);
        }

        return $reloaded;
    }
}
