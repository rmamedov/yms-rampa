<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Booking\BookingCreationService;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\Exception\BookingLimitExceededException;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Booking\Exception\SlotNotAvailableException;
use App\Domain\Booking\Exception\SlotReservedException;
use App\Domain\Booking\Exception\SupplierNotAllowedException;
use App\Domain\Booking\Exception\VehicleTimeConflictException;
use App\Domain\Booking\Exception\VehicleTooHeavyException;
use App\Domain\Hold\Exception\SlotHeldException;
use App\Domain\Slot\DateOutOfHorizonException;
use App\Domain\Slot\ReservedSlotRule;
use App\Domain\Store\StorePolicy;
use App\Domain\Supplier\SupplierInfo;
use App\Tests\Support\BookingFactory;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Створення планового бронювання: BOOK-01..BOOK-09.
 */
#[CoversClass(BookingCreationService::class)]
final class CreateBookingTest extends TestCase
{
    /** BOOK-06: документ у статусі booked, type=scheduled, подія BookingCreated. */
    public function testCreatesBookedScheduledBookingAndPublishesEvent(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();

        self::assertSame(BookingStatus::Booked, $booking->status());
        self::assertSame(BookingType::Scheduled, $booking->type);
        self::assertSame('ТОВ Молокія', $booking->supplierNameSnapshot);
        self::assertSame('Сільпо Хрещатик', $booking->storeSnapshot->displayName);

        $events = $scenario->outbox->eventsOfType('BookingCreated');

        self::assertCount(1, $events);
        self::assertSame('scheduled', $events[0]->payload['type']);
        self::assertNull($events[0]->payload['rescheduleOf']);
    }

    /** BOOK-08: гонка двох постачальників — рівно один успішний запис. */
    public function testRaceBetweenTwoSuppliersLeavesExactlyOneBooking(): void
    {
        $scenario = new Scenario();
        $scenario->book(supplierId: Scenario::SUPPLIER_ID);

        try {
            $scenario->creation->create(
                $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
                $scenario->request(),
                $scenario->now(),
            );
            self::fail('Другий постачальник не мав отримати той самий слот');
        } catch (SlotAlreadyBookedException $error) {
            self::assertSame('SLOT_ALREADY_BOOKED', $error->errorCode());
            self::assertSame(409, $error->httpStatus());
            self::assertSame('Слот щойно забронював інший постачальник', $error->getMessage());
        }

        self::assertNotNull($scenario->bookings->findActiveBySlotKey($scenario->slotKey()));
        self::assertCount(1, $scenario->bookings->all());
    }

    /**
     * BOOK-07: атомарність на рівні сховища — два послідовні виклики вставки
     * на той самий ключ слота (сітка застаріла, Redis недоступний).
     */
    public function testAtomicInsertAllowsOnlyOneActiveBookingPerSlotKey(): void
    {
        $scenario = new Scenario();
        $first = BookingFactory::scheduled(id: 'bk-a');
        $second = BookingFactory::scheduled(supplierId: Scenario::OTHER_SUPPLIER_ID, id: 'bk-b');

        $scenario->bookings->insertIfSlotFree($first, []);

        $this->expectException(SlotAlreadyBookedException::class);
        $scenario->bookings->insertIfSlotFree($second, []);
    }

    /** BOOK-07: скасовані бронювання індексом не блокуються — слот вільний. */
    public function testCancelledBookingReleasesSlotKeyForRebooking(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();

        $scenario->lifecycle->cancel($scenario->supplier(), $booking->id, $scenario->now());

        $rebooked = $scenario->creation->create(
            $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
            $scenario->request(),
            $scenario->now(),
        );

        self::assertSame(BookingStatus::Booked, $rebooked->status());
        self::assertNotSame($booking->id, $rebooked->id);
    }

    /** BOOK-01: тоннаж перевіряється на сервері навіть при обході UI. */
    public function testVehicleTooHeavyIsRejectedOnServer(): void
    {
        $scenario = new Scenario();

        $this->expectException(VehicleTooHeavyException::class);
        $scenario->book(vehicle: Scenario::vehicle(weightTons: 25.0));
    }

    /** BOOK-02: неактивний постачальник. */
    public function testSuspendedSupplierCannotBook(): void
    {
        $scenario = new Scenario();
        $scenario->suppliers->add(new SupplierInfo(Scenario::SUPPLIER_ID, 'ТОВ Молокія', active: false));

        try {
            $scenario->book();
            self::fail('Неактивний постачальник не має створювати бронювання');
        } catch (SupplierNotAllowedException $error) {
            self::assertSame('SUPPLIER_NOT_ALLOWED', $error->errorCode());
            self::assertSame(403, $error->httpStatus());
        }
    }

    /** BOOK-02: постачальник без доступу до цієї філії. */
    public function testSupplierWithoutStoreAccessCannotBook(): void
    {
        $scenario = new Scenario();
        $scenario->suppliers->add(new SupplierInfo(
            Scenario::SUPPLIER_ID,
            'ТОВ Молокія',
            allowedStoreIds: ['store-99'],
        ));

        $this->expectException(SupplierNotAllowedException::class);
        $scenario->book();
    }

    /** BOOK-03 / GRID-02: слот у межах lead time недоступний. */
    public function testLeadTimeIsRecheckedOnConfirmation(): void
    {
        $scenario = new Scenario();

        try {
            $scenario->book('2026-08-27 09:30');
            self::fail('Слот у межах lead time мав бути відхилений');
        } catch (SlotNotAvailableException $error) {
            self::assertSame('SLOT_NOT_AVAILABLE', $error->errorCode());
            self::assertStringContainsString('60 хв', $error->getMessage());
        }
    }

    /** GRID-03: дата поза горизонтом бронювання. */
    public function testDateBeyondHorizonIsRejected(): void
    {
        $scenario = new Scenario();

        try {
            $scenario->book('2026-09-15 10:00');
            self::fail('Дата поза горизонтом мала бути відхилена');
        } catch (DateOutOfHorizonException $error) {
            self::assertSame(14, $error->horizonDays);
            self::assertStringContainsString('14 днів', $error->getMessage());
        }
    }

    /** BOOK-05 / GRID-04: резерв чужого постачальника → 403 SLOT_RESERVED. */
    public function testForeignReservedSlotIsForbidden(): void
    {
        $scenario = new Scenario();
        $scenario->overlays->addReservedRule(Scenario::STORE_ID, new ReservedSlotRule(
            supplierId: Scenario::OTHER_SUPPLIER_ID,
            rampId: 'r1',
            slotStartTime: '11:00',
            dayOfWeek: 5,
        ));

        try {
            $scenario->book('2026-08-28 11:00');
            self::fail('Чужий резерв не мав бути заброньований');
        } catch (SlotReservedException $error) {
            self::assertSame('SLOT_RESERVED', $error->errorCode());
            self::assertSame(403, $error->httpStatus());
            self::assertStringNotContainsString(Scenario::OTHER_SUPPLIER_ID, $error->getMessage());
        }
    }

    /** GRID-04: власний резерв бронюється звичайним чином. */
    public function testOwnReservedSlotIsBookable(): void
    {
        $scenario = new Scenario();
        $scenario->overlays->addReservedRule(Scenario::STORE_ID, new ReservedSlotRule(
            supplierId: Scenario::SUPPLIER_ID,
            rampId: 'r1',
            slotStartTime: '11:00',
            dayOfWeek: 5,
        ));

        $booking = $scenario->book('2026-08-28 11:00');

        self::assertSame(BookingStatus::Booked, $booking->status());
    }

    /** BOOK-04: перетин за одним держномером — попередження, а не блокер. */
    public function testOverlappingVehicleProducesWarning(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00', rampId: 'r1');

        try {
            $scenario->book('2026-08-28 10:00', rampId: 'r2');
            self::fail('Очікувалося попередження про перетин авто');
        } catch (VehicleTimeConflictException $error) {
            self::assertSame('VEHICLE_TIME_CONFLICT', $error->errorCode());
            self::assertTrue($error->problemExtensions()['warning']);
            self::assertCount(1, $error->conflicts);
        }
    }

    public function testOverlappingVehicleIsAcceptedWithConfirmFlag(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00', rampId: 'r1');

        $second = $scenario->book('2026-08-28 10:00', rampId: 'r2', confirmConflict: true);

        self::assertSame(BookingStatus::Booked, $second->status());
        self::assertCount(2, $scenario->bookings->all());
    }

    /** EDGE-01: сусідні непересічні слоти того самого авто — без попереджень. */
    public function testAdjacentSlotsOfSameVehicleAreAllowed(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00', rampId: 'r1');

        $second = $scenario->book('2026-08-28 10:30', rampId: 'r1');

        self::assertSame(BookingStatus::Booked, $second->status());
    }

    /** BOOK-04: номери різних постачальників можуть збігатися. */
    public function testSamePlateOfDifferentSupplierDoesNotConflict(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00', rampId: 'r1', supplierId: Scenario::SUPPLIER_ID);

        $second = $scenario->book('2026-08-28 10:00', rampId: 'r2', supplierId: Scenario::OTHER_SUPPLIER_ID);

        self::assertSame(Scenario::OTHER_SUPPLIER_ID, $second->supplierId);
    }

    /** BOOK-09: анти-сквотинг. */
    public function testActiveBookingLimitIsEnforced(): void
    {
        $scenario = new Scenario(settings: Scenario::storeSettings(
            policy: new StorePolicy(maxActiveBookingsPerSupplier: 1),
        ));

        $scenario->book('2026-08-28 10:00', rampId: 'r1');

        try {
            $scenario->book('2026-08-28 11:00', rampId: 'r1', vehicle: Scenario::vehicle('BC7777CT'));
            self::fail('Перевищення ліміту мало бути відхилене');
        } catch (BookingLimitExceededException $error) {
            self::assertSame('BOOKING_LIMIT_EXCEEDED', $error->errorCode());
            self::assertSame(422, $error->httpStatus());
            self::assertStringContainsString('(1)', $error->getMessage());
        }
    }

    /** BOOK-09: walk-in магазину в ліміт постачальника не входять. */
    public function testWalkInDoesNotCountTowardsSupplierLimit(): void
    {
        $scenario = new Scenario(settings: Scenario::storeSettings(
            policy: new StorePolicy(maxActiveBookingsPerSupplier: 1),
        ));

        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );

        $booking = $scenario->book('2026-08-28 10:00');

        self::assertSame(BookingStatus::Booked, $booking->status());
    }

    /** HOLD-03: підтвердити бронювання може лише власник холду. */
    public function testSlotHeldByAnotherUserCannotBeBooked(): void
    {
        $scenario = new Scenario();
        $scenario->holdService->hold(
            $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
            $scenario->slotKey(),
            $scenario->now(),
        );

        try {
            $scenario->book();
            self::fail('Слот під чужим холдом не мав бути заброньований');
        } catch (SlotHeldException $error) {
            self::assertSame('SLOT_HELD', $error->errorCode());
            self::assertSame(409, $error->httpStatus());
        }
    }

    /** BOOK-06: успішне бронювання знімає власний холд. */
    public function testOwnHoldIsReleasedAfterBooking(): void
    {
        $scenario = new Scenario();
        $hold = $scenario->holdService->hold($scenario->supplier(), $scenario->slotKey(), $scenario->now());

        $scenario->creation->create(
            $scenario->supplier(),
            $scenario->request(holdToken: $hold->holdToken),
            $scenario->now(),
        );

        self::assertNull($scenario->holds->get($scenario->slotKey(), $scenario->now()));
    }

    /** Слот поза сіткою магазину (не збігається з розміткою вікна прийому). */
    public function testSlotOutsideGridIsRejected(): void
    {
        $scenario = new Scenario();

        try {
            $scenario->book('2026-08-28 10:07');
            self::fail('Слот поза розміткою сітки мав бути відхилений');
        } catch (SlotNotAvailableException $error) {
            self::assertStringContainsString('немає в сітці', $error->getMessage());
        }
    }
}
