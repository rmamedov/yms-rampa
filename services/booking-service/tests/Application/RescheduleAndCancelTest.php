<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Booking\BookingCreationService;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\Exception\EditDeadlinePassedException;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Booking\Exception\VehicleTooHeavyException;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Редагування, перенесення і скасування бронювань: EDIT-01..EDIT-06.
 */
#[CoversClass(BookingCreationService::class)]
final class RescheduleAndCancelTest extends TestCase
{
    /** EDIT-01: перенесення = нове бронювання з rescheduleOf + скасування старого. */
    public function testRescheduleCreatesNewBookingAndCancelsOld(): void
    {
        $scenario = new Scenario();
        $old = $scenario->book('2026-08-28 10:00');

        $new = $scenario->creation->reschedule(
            $scenario->supplier(),
            $old->id,
            $scenario->request('2026-08-28 12:00'),
            $scenario->now(),
        );

        self::assertSame($old->id, $new->rescheduleOf);
        self::assertSame(BookingStatus::Booked, $new->status());
        self::assertSame(BookingStatus::Cancelled, $scenario->reload($old)->status());

        // Публікується саме пара подій; окремої BookingRescheduled не існує.
        $types = $scenario->outbox->eventTypes();

        self::assertSame(['BookingCreated', 'BookingCreated', 'BookingCancelled', 'SlotReleased'], $types);
    }

    /** EDIT-01: якщо новий слот зайнятий — старе бронювання лишається чинним. */
    public function testFailedRescheduleLeavesOldBookingIntact(): void
    {
        $scenario = new Scenario();
        $old = $scenario->book('2026-08-28 10:00', rampId: 'r1');
        $scenario->book(
            '2026-08-28 12:00',
            rampId: 'r1',
            vehicle: Scenario::vehicle('BC7777CT'),
            supplierId: Scenario::OTHER_SUPPLIER_ID,
        );

        try {
            $scenario->creation->reschedule(
                $scenario->supplier(),
                $old->id,
                $scenario->request('2026-08-28 12:00'),
                $scenario->now(),
            );
            self::fail('Перенесення на зайнятий слот мало впасти');
        } catch (SlotAlreadyBookedException) {
            // очікувано
        }

        $reloaded = $scenario->reload($old);

        self::assertSame(BookingStatus::Booked, $reloaded->status());
        self::assertNull($reloaded->cancelledAt());
        self::assertNotNull($scenario->bookings->findActiveBySlotKey($scenario->slotKey('2026-08-28 10:00')));
    }

    /** EDIT-03: скасування публікує SlotReleased і звільняє слот. */
    public function testCancelPublishesSlotReleased(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();

        $scenario->lifecycle->cancel($scenario->supplier(), $booking->id, $scenario->now(), 'змінилися плани');

        $released = $scenario->outbox->eventsOfType('SlotReleased');

        self::assertCount(1, $released);
        self::assertSame($booking->id, $released[0]->payload['releasedBookingId']);
        self::assertNull($scenario->bookings->findActiveBySlotKey($scenario->slotKey()));
        self::assertSame('змінилися плани', $scenario->reload($booking)->cancellation()?->reason);
    }

    /** EDIT-02: після дедлайну постачальник скасувати не може. */
    public function testSupplierCannotCancelAfterDeadline(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $this->expectException(EditDeadlinePassedException::class);
        $scenario->lifecycle->cancel(
            $scenario->supplier(),
            $booking->id,
            Scenario::kyiv('2026-08-28 09:00'),
        );
    }

    /** EDIT-02: магазин дедлайном не обмежений. */
    public function testStoreCanCancelAfterDeadline(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $cancelled = $scenario->lifecycle->cancel(
            $scenario->storeStaff(),
            $booking->id,
            Scenario::kyiv('2026-08-28 09:00'),
        );

        self::assertSame(BookingStatus::Cancelled, $cancelled->status());
        self::assertSame('store', $cancelled->cancellation()?->by->value);
    }

    /** EDIT-05: зміна водія дозволена після дедлайну і публікує BookingReassigned. */
    public function testDriverChangeIsAllowedAfterDeadline(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: 'du-1');

        $updated = $scenario->lifecycle->reassign(
            actor: $scenario->supplier(),
            bookingId: $booking->id,
            now: Scenario::kyiv('2026-08-28 09:30'),
            driverId: 'du-2',
            driverProvided: true,
        );

        self::assertSame('du-2', $updated->driverId());
        self::assertNotEmpty($scenario->outbox->eventsOfType('BookingReassigned'));
    }

    /** EDIT-05: при заміні авто повторно виконується перевірка тоннажу. */
    public function testVehicleChangeRechecksWeightLimit(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $this->expectException(VehicleTooHeavyException::class);
        $scenario->lifecycle->reassign(
            actor: $scenario->supplier(),
            bookingId: $booking->id,
            now: Scenario::kyiv('2026-08-28 09:30'),
            vehicle: Scenario::vehicle('BC7777CT', 26.0),
        );
    }

    public function testVehicleChangeWithinLimitIsApplied(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $updated = $scenario->lifecycle->reassign(
            actor: $scenario->supplier(),
            bookingId: $booking->id,
            now: Scenario::kyiv('2026-08-28 09:30'),
            vehicle: Scenario::vehicle('BC7777CT', 12.0),
        );

        self::assertSame('BC7777CT', $updated->vehicle()->plateNumber);
    }

    /** EDIT-04: редагування orderId і palletsCount до дедлайну. */
    public function testDetailsCanBeUpdatedBeforeDeadline(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $updated = $scenario->lifecycle->updateDetails(
            actor: $scenario->supplier(),
            bookingId: $booking->id,
            now: $scenario->now(),
            orderId: 'ORD-55871',
            palletsCount: 12,
            orderIdProvided: true,
        );

        self::assertSame('ORD-55871', $updated->orderId());
        self::assertSame(12, $updated->palletsCount());
    }

    public function testDetailsCannotBeUpdatedAfterDeadline(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $this->expectException(EditDeadlinePassedException::class);
        $scenario->lifecycle->updateDetails(
            actor: $scenario->supplier(),
            bookingId: $booking->id,
            now: Scenario::kyiv('2026-08-28 09:00'),
            palletsCount: 12,
        );
    }

    /** EDIT-06: переведення на іншу вільну рампу того самого слота. */
    public function testStoreMovesBookingToFreeRamp(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', rampId: 'r1');

        $moved = $scenario->lifecycle->moveToRamp(
            $scenario->storeStaff(),
            $booking->id,
            'r2',
            Scenario::kyiv('2026-08-28 09:50'),
        );

        self::assertSame('r2', $moved->rampId());
        self::assertNull($scenario->bookings->findActiveBySlotKey($scenario->slotKey('2026-08-28 10:00', 'r1')));
        self::assertNotNull($scenario->bookings->findActiveBySlotKey($scenario->slotKey('2026-08-28 10:00', 'r2')));

        // Звільняється саме ПОПЕРЕДНЯ рампа, а не та, куди перевели.
        $released = $scenario->outbox->eventsOfType('SlotReleased');

        self::assertCount(1, $released);
        self::assertSame('r1', $released[0]->payload['rampId']);
    }

    /** EDIT-06: цільова рампа має бути вільною. */
    public function testMoveToOccupiedRampIsRejected(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', rampId: 'r1');
        $scenario->book(
            '2026-08-28 10:00',
            rampId: 'r2',
            vehicle: Scenario::vehicle('BC7777CT'),
            supplierId: Scenario::OTHER_SUPPLIER_ID,
        );

        $this->expectException(SlotAlreadyBookedException::class);
        $scenario->lifecycle->moveToRamp(
            $scenario->storeStaff(),
            $booking->id,
            'r2',
            Scenario::kyiv('2026-08-28 09:50'),
        );
    }
}
