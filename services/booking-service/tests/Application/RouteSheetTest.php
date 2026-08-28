<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\RouteSheet\RouteSheetService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Booking\DelayReason;
use App\Domain\RouteSheet\RouteSheetEntry;
use App\Infrastructure\InMemory\SequentialIdGenerator;
use App\Infrastructure\Store\FixtureStoreConfigProvider;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Маршрутні листи RSHT-01..RSHT-04 і DATA-15.
 */
#[CoversClass(RouteSheetService::class)]
final class RouteSheetTest extends TestCase
{
    /** RSHT-01: лист створюється автоматично при першому бронюванні дати. */
    public function testSheetIsCreatedOnFirstBooking(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $sheet = $scenario->routeSheetRepository->findBySupplierAndDate(Scenario::SUPPLIER_ID, '2026-08-28');

        self::assertNotNull($sheet);
        self::assertSame([$booking->id], array_map(
            static fn (RouteSheetEntry $entry) => $entry->bookingId,
            $sheet->entries(),
        ));
        self::assertSame($sheet->id, $scenario->reload($booking)->routeSheetId());
    }

    /** RSHT-01 / RSHT-03: точки впорядковані за часом слоту. */
    public function testEntriesAreOrderedBySlotTime(): void
    {
        $scenario = new Scenario();
        $late = $scenario->book('2026-08-28 12:00', rampId: 'r1');
        $early = $scenario->book('2026-08-28 10:00', rampId: 'r1');

        $sheet = $scenario->routeSheets->sync(Scenario::SUPPLIER_ID, '2026-08-28');

        self::assertSame([$early->id, $late->id], array_map(
            static fn (RouteSheetEntry $entry) => $entry->bookingId,
            $sheet->entries(),
        ));
        self::assertSame([1, 2], array_map(
            static fn (RouteSheetEntry $entry) => $entry->sortOrder,
            $sheet->entries(),
        ));
    }

    /** DATA-15: скасування виключає точку і інкрементує printVersion. */
    public function testCancelledBookingIsRemovedFromSheet(): void
    {
        $scenario = new Scenario();
        $first = $scenario->book('2026-08-28 10:00');
        $second = $scenario->book('2026-08-28 12:00');

        $before = $scenario->routeSheets->sync(Scenario::SUPPLIER_ID, '2026-08-28')->printVersion();
        $scenario->lifecycle->cancel($scenario->supplier(), $first->id, $scenario->now());
        $after = $scenario->routeSheets->sync(Scenario::SUPPLIER_ID, '2026-08-28');

        self::assertSame([$second->id], array_map(
            static fn (RouteSheetEntry $entry) => $entry->bookingId,
            $after->entries(),
        ));
        self::assertGreaterThan($before, $after->printVersion());
    }

    /** RSHT-02: призначення водія на весь лист. */
    public function testDriverAssignedToWholeSheet(): void
    {
        $scenario = new Scenario();
        $first = $scenario->book('2026-08-28 10:00');
        $second = $scenario->book('2026-08-28 12:00');

        $sheet = $scenario->routeSheets->assignDriverToSheet(
            $scenario->supplier(),
            Scenario::SUPPLIER_ID,
            '2026-08-28',
            'du-7',
            $scenario->now(),
        );

        self::assertSame(['du-7', 'du-7'], array_map(
            static fn (RouteSheetEntry $entry) => $entry->driverId,
            $sheet->entries(),
        ));
        self::assertSame('du-7', $scenario->reload($first)->driverId());
        self::assertSame('du-7', $scenario->reload($second)->driverId());
    }

    /**
     * RSHT-02, ISSUE-18: порожній driverId знімає водія з УСЬОГО листа.
     *
     * Раніше маршрут вимагав обовʼязковий driverId, тому кабінет постачальника
     * пропонував варіант «Водія не призначено», який нічого не робив, — список
     * показував стан, якого не сталося. Зняття має проходити тим самим шляхом,
     * що й призначення: і лист, і кожне бронювання.
     */
    public function testDriverRemovedFromWholeSheet(): void
    {
        $scenario = new Scenario();
        $first = $scenario->book('2026-08-28 10:00');
        $second = $scenario->book('2026-08-28 12:00');

        $scenario->routeSheets->assignDriverToSheet(
            $scenario->supplier(),
            Scenario::SUPPLIER_ID,
            '2026-08-28',
            'du-7',
            $scenario->now(),
        );

        $sheet = $scenario->routeSheets->assignDriverToSheet(
            $scenario->supplier(),
            Scenario::SUPPLIER_ID,
            '2026-08-28',
            null,
            $scenario->now(),
        );

        self::assertSame([null, null], array_map(
            static fn (RouteSheetEntry $entry) => $entry->driverId,
            $sheet->entries(),
        ));
        self::assertNull($scenario->reload($first)->driverId());
        self::assertNull($scenario->reload($second)->driverId());
        // RSHT-04: у застосунку водія лист зникає одразу, а не після
        // перезавантаження кабінету.
        self::assertSame([], $scenario->routeSheets->forDriver('du-7', '2026-08-28'));
    }

    /** RSHT-02: призначення на окреме бронювання перекриває призначення листа. */
    public function testBookingLevelDriverOverridesSheetDriver(): void
    {
        $scenario = new Scenario();
        $first = $scenario->book('2026-08-28 10:00');
        $second = $scenario->book('2026-08-28 12:00');

        $scenario->routeSheets->assignDriverToSheet(
            $scenario->supplier(),
            Scenario::SUPPLIER_ID,
            '2026-08-28',
            'du-7',
            $scenario->now(),
        );
        $sheet = $scenario->routeSheets->assignDriverToBooking(
            $scenario->supplier(),
            $second->id,
            'du-8',
            $scenario->now(),
        );

        self::assertSame('du-7', $sheet->driverFor($first->id));
        self::assertSame('du-8', $sheet->driverFor($second->id));
        self::assertSame('du-8', $scenario->reload($second)->driverId());
    }

    /** RSHT-04: водій бачить лише власні точки. */
    public function testDriverSeesOnlyOwnPoints(): void
    {
        $scenario = new Scenario();
        $first = $scenario->book('2026-08-28 10:00');
        $second = $scenario->book('2026-08-28 12:00');

        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $first->id, 'du-7', $scenario->now());
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $second->id, 'du-8', $scenario->now());

        $sheets = $scenario->routeSheets->forDriver('du-7', '2026-08-28');

        self::assertCount(1, $sheets);
        self::assertCount(1, $sheets[0]['points']);
        self::assertSame($first->id, $sheets[0]['points'][0]['bookingId']);
    }

    /** RSHT-02: без призначення водій бронювання не бачить. */
    public function testUnassignedBookingIsInvisibleToDrivers(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');

        self::assertSame([], $scenario->routeSheets->forDriver('du-7', '2026-08-28'));
    }

    /** RSHT-03: склад друкованої версії. */
    public function testPrintViewContainsRequiredFields(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->create(
            $scenario->supplier(),
            $scenario->request('2026-08-28 10:00', orderId: 'ORD-55871'),
            $scenario->now(),
        );
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $view = $scenario->routeSheets->printView($scenario->supplier(), Scenario::SUPPLIER_ID, '2026-08-28');
        $point = $view['points'][0];

        self::assertSame('ТОВ Молокія', $view['supplierName']);
        self::assertSame('2026-08-28', $view['date']);
        self::assertSame('Київ', $point['city']);
        self::assertSame('вул. Хрещатик, 12', $point['address']);
        self::assertSame('10:00', $point['localTime']);
        self::assertSame('r1', $point['rampId']);
        self::assertSame('ORD-55871', $point['orderId']);
        self::assertSame(8, $point['palletsCount']);
        self::assertSame('du-7', $point['driverId']);
    }

    /**
     * DRV-21: точка несе КООРДИНАТИ філії, а не лише текстову адресу —
     * інакше «Побудувати маршрут» відкриває пошук, а не точку на карті.
     */
    public function testDriverPointCarriesBranchCoordinates(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $point = $scenario->routeSheets->forDriver('du-7', '2026-08-28')[0]['points'][0];

        self::assertSame(50.49699, $point['latitude']);
        self::assertSame(30.36123, $point['longitude']);
        // Адреса лишається — вона запасний варіант і рядок для друку.
        self::assertSame('Київ', $point['city']);
        self::assertSame('вул. Хрещатик, 12', $point['address']);
    }

    /** Філія без координат не ламає лист — поля просто порожні. */
    public function testPointWithoutCoordinatesKeepsNullsInsteadOfFailing(): void
    {
        $scenario = new Scenario(settings: Scenario::storeSettings(withLocation: false));
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $point = $scenario->routeSheets->forDriver('du-7', '2026-08-28')[0]['points'][0];

        self::assertNull($point['latitude']);
        self::assertNull($point['longitude']);
    }

    /**
     * DRV-21: водієві потрібні номер і назва рампи з довідника філії —
     * на воротах написано «2», а не службове «r2».
     */
    public function testDriverPointCarriesRampNumberAndName(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', rampId: 'r2');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $point = $scenario->routeSheets->forDriver('du-7', '2026-08-28')[0]['points'][0];

        self::assertSame(2, $point['rampNumber']);
        self::assertSame('Холодильна', $point['rampName']);
        // Ідентифікатор лишається в контракті — за ним ідуть дії.
        self::assertSame('r2', $point['rampId']);
    }

    /** Магазин зник із довідника — номер рампи невідомий, лист усе одно віддається. */
    public function testUnknownStoreLeavesRampNumberEmpty(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        // Той самий лист, але довідник більше не знає цієї філії (STORE_NOT_FOUND).
        $sheets = new RouteSheetService(
            $scenario->routeSheetRepository,
            $scenario->bookings,
            new SequentialIdGenerator('rs-'),
            new FixtureStoreConfigProvider(strict: true),
        );

        $point = $sheets->forDriver('du-7', '2026-08-28')[0]['points'][0];

        self::assertNull($point['rampNumber']);
        self::assertNull($point['rampName']);
        self::assertNull($point['latitude']);
        self::assertNull($point['longitude']);
        self::assertSame('r1', $point['rampId']);
    }

    /**
     * DLY-01: позначка затримки і час прибуття живуть у САМІЙ проєкції листа,
     * тож переживають перезавантаження сторінки і підтверджуються полінгом.
     */
    public function testDelayAndArrivalSurviveRouteSheetReload(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-27 12:00');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $scenario->driverBookings->reportDelay(
            $scenario->driver('du-7'),
            $booking->id,
            DelayReason::TrafficJam,
            Scenario::kyiv('2026-08-27 12:45'),
            $scenario->now(),
        );
        $scenario->driverBookings->markArrived($scenario->driver('du-7'), $booking->id, $scenario->now());

        // Свіже читання листа — рівно те, що зробить полінг після F5.
        $point = $scenario->routeSheets->forDriver('du-7', '2026-08-27')[0]['points'][0];

        self::assertTrue($point['delayed']['flag']);
        self::assertSame('затори', $point['delayed']['reason']);
        self::assertSame('2026-08-27T09:45:00Z', $point['delayed']['eta']);
        self::assertSame('2026-08-27T06:00:00Z', $point['arrivedAt']);
        self::assertSame('arrived', $point['status']);
    }

    /** Без затримки і без прибуття поля присутні, але порожні. */
    public function testUntouchedPointHasEmptyDelayAndArrival(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $point = $scenario->routeSheets->forDriver('du-7', '2026-08-28')[0]['points'][0];

        self::assertSame(['flag' => false, 'reason' => null, 'eta' => null], $point['delayed']);
        self::assertNull($point['arrivedAt']);
    }

    /** Лист чужого постачальника недоступний. */
    public function testForeignSupplierCannotReadRouteSheet(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');

        $this->expectException(AccessDeniedException::class);
        $scenario->routeSheets->printView(
            $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
            Scenario::SUPPLIER_ID,
            '2026-08-28',
        );
    }

    /** EDIT-05: зміна водія бронювання оновлює листи обох водіїв негайно. */
    public function testDriverChangeMovesPointBetweenDrivers(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: 'du-7');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $scenario->lifecycle->reassign(
            actor: $scenario->supplier(),
            bookingId: $booking->id,
            now: $scenario->now(),
            driverId: 'du-8',
            driverProvided: true,
        );

        self::assertSame([], $scenario->routeSheets->forDriver('du-7', '2026-08-28'));
        self::assertCount(1, $scenario->routeSheets->forDriver('du-8', '2026-08-28'));
    }

    /** Walk-in магазину до маршрутних листів постачальника не потрапляє. */
    public function testWalkInIsNotAddedToRouteSheet(): void
    {
        $scenario = new Scenario();
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );

        $sheet = $scenario->routeSheetRepository->findBySupplierAndDate(Scenario::SUPPLIER_ID, '2026-08-27');

        self::assertNull($sheet);
    }
}
