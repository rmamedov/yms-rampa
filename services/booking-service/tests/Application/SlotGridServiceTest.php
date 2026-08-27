<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Slot\SlotGridService;
use App\Domain\Slot\ReservedSlotRule;
use App\Domain\Slot\Slot;
use App\Domain\Slot\SlotBlock;
use App\Domain\Slot\SlotState;
use App\Domain\Store\StoreNotFoundException;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Складання сітки з матеріалізованих фактів (GRID-01 кроки 5–8, GRID-04..GRID-06).
 */
#[CoversClass(SlotGridService::class)]
final class SlotGridServiceTest extends TestCase
{
    /** GRID-06: вікно 08:00–14:00, слот 30 хв, 2 рампи → 24 слоти. */
    public function testGridContainsTwentyFourSlotsForTwoRamps(): void
    {
        $scenario = new Scenario();
        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->supplier(), $scenario->now());

        self::assertCount(24, $grid->slots);
        self::assertSame(24, $grid->countInState(SlotState::Available));
    }

    /** GRID-06: блокування рампи 2 на 10:00–12:00 прибирає рівно 4 слоти. */
    public function testBlockRemovesExactlyFourSlotsFromAvailable(): void
    {
        $scenario = new Scenario();
        $scenario->overlays->addBlock(new SlotBlock(
            storeId: Scenario::STORE_ID,
            rampId: 'r2',
            from: Scenario::kyiv('2026-08-28 10:00'),
            to: Scenario::kyiv('2026-08-28 12:00'),
            reason: 'ремонт рампи',
        ));

        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->supplier(), $scenario->now());

        self::assertSame(4, $grid->countInState(SlotState::Blocked));
        self::assertSame(20, $grid->countInState(SlotState::Available));
    }

    /** GRID-01, крок 7: активні бронювання накладаються станом booked. */
    public function testActiveBookingMakesSlotBooked(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00', rampId: 'r1');

        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->supplier(), $scenario->now());

        self::assertSame(1, $grid->countInState(SlotState::Booked));
        self::assertSame(23, $grid->countInState(SlotState::Available));
    }

    /** SLOT-05 / EDIT-03: скасоване бронювання повертає слот у available. */
    public function testCancelledBookingRestoresAvailableSlot(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', rampId: 'r1');
        $scenario->lifecycle->cancel($scenario->supplier(), $booking->id, $scenario->now());

        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->supplier(), $scenario->now());

        self::assertSame(0, $grid->countInState(SlotState::Booked));
        self::assertSame(24, $grid->countInState(SlotState::Available));
    }

    /** GRID-04: власний резерв — доступний з позначкою «зарезервовано для вас». */
    public function testOwnReservedSlotIsAvailableForViewer(): void
    {
        $scenario = new Scenario();
        $scenario->overlays->addReservedRule(Scenario::STORE_ID, new ReservedSlotRule(
            supplierId: Scenario::SUPPLIER_ID,
            rampId: 'r1',
            slotStartTime: '11:00',
            dayOfWeek: 5,
        ));

        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->supplier(), $scenario->now());
        $reserved = array_values(array_filter(
            $grid->slots,
            static fn (Slot $slot) => $slot->reservedForViewer,
        ));

        self::assertCount(1, $reserved);
        self::assertSame(SlotState::Available, $reserved[0]->state);
        self::assertSame(24, $grid->countInState(SlotState::Available));
    }

    /** GRID-04: чужий резерв недоступний і не розкриває, за ким закріплений. */
    public function testForeignReservedSlotIsHiddenFromOtherSuppliers(): void
    {
        $scenario = new Scenario();
        $scenario->overlays->addReservedRule(Scenario::STORE_ID, new ReservedSlotRule(
            supplierId: Scenario::SUPPLIER_ID,
            rampId: 'r1',
            slotStartTime: '11:00',
            dayOfWeek: 5,
        ));

        $grid = $scenario->grid->grid(
            Scenario::STORE_ID,
            '2026-08-28',
            $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
            $scenario->now(),
        );
        $reserved = $grid->slotsInState(SlotState::Reserved);

        self::assertCount(1, $reserved);
        self::assertFalse($reserved[0]->isSelectable());
        self::assertNull($reserved[0]->reservedForSupplierId);
    }

    /** GRID-02: слоти в межах lead time позначаються past. */
    public function testLeadTimeMarksNearestSlotsAsPast(): void
    {
        $scenario = new Scenario();
        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-27', $scenario->supplier(), $scenario->now());

        // now = 09:00, lead time 60 хв → недоступні всі слоти до 10:00 (по 2 рампи).
        self::assertSame(8, $grid->countInState(SlotState::Past));
    }

    /** GRID-05: у відповіді є параметри для валідації і таймерів на клієнті. */
    public function testGridCarriesStoreParametersAndServerNow(): void
    {
        $scenario = new Scenario();
        $payload = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->supplier(), $scenario->now())->toArray();

        self::assertEqualsWithDelta(20.0, $payload['maxVehicleWeightTons'], 0.001);
        self::assertSame(30, $payload['slotSizeMinutes']);
        self::assertSame(60, $payload['leadTimeMinutes']);
        self::assertSame('2026-08-27T06:00:00Z', $payload['now']);
    }

    /** GRID-01, крок 2: магазин з ymsStatus ≠ active для постачальника не існує. */
    public function testInactiveStoreIsNotFoundForSupplier(): void
    {
        $scenario = new Scenario(settings: Scenario::storeSettings(ymsActive: false));

        $this->expectException(StoreNotFoundException::class);
        $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->supplier(), $scenario->now());
    }

    /** Співробітники мережі бачать конфігурацію відключеної філії. */
    public function testInactiveStoreIsVisibleForNetworkAdmin(): void
    {
        $scenario = new Scenario(settings: Scenario::storeSettings(ymsActive: false));
        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-28', $scenario->networkAdmin(), $scenario->now());

        self::assertCount(24, $grid->slots);
    }

    public function testUnknownStoreIsNotFound(): void
    {
        $scenario = new Scenario();

        $this->expectException(StoreNotFoundException::class);
        $scenario->grid->grid('store-404', '2026-08-28', $scenario->supplier(), $scenario->now());
    }

    /** Неділя без вікна прийому — порожня сітка. */
    public function testSundayHasNoSlots(): void
    {
        $scenario = new Scenario();
        $grid = $scenario->grid->grid(Scenario::STORE_ID, '2026-08-30', $scenario->supplier(), $scenario->now());

        self::assertSame([], $grid->slots);
    }

    /** Межі локальної доби магазину рахуються в Europe/Kyiv. */
    public function testLocalDayRangeIsComputedInStoreTimezone(): void
    {
        [$from, $to] = SlotGridService::localDayRange('2026-08-28');

        self::assertSame('2026-08-27T21:00:00Z', $from->format('Y-m-d\TH:i:s\Z'));
        self::assertSame('2026-08-28T21:00:00Z', $to->format('Y-m-d\TH:i:s\Z'));
    }
}
