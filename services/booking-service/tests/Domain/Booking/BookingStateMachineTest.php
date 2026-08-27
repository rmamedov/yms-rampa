<?php

declare(strict_types=1);

namespace App\Tests\Domain\Booking;

use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\DelayReason;
use App\Domain\Booking\Exception\InvalidStatusTransitionException;
use App\Domain\Booking\Exception\TransitionNotAllowedException;
use App\Domain\Booking\PartialUnload;
use App\Domain\Booking\PartialUnloadReason;
use App\Domain\Booking\RejectionReason;
use App\Domain\Event\EventType;
use App\Domain\Exception\ValidationFailedException;
use App\Tests\Support\BookingFactory;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Машина станів бронювання ST-01..ST-07 разом із правами на переходи.
 */
#[CoversClass(\App\Domain\Booking\Booking::class)]
final class BookingStateMachineTest extends TestCase
{
    public function testScheduledBookingStartsAsBooked(): void
    {
        $booking = BookingFactory::scheduled();

        self::assertSame(BookingStatus::Booked, $booking->status());
        self::assertSame(BookingType::Scheduled, $booking->type);
        self::assertCount(1, $booking->statusHistory());
        self::assertNull($booking->statusHistory()[0]->from);
    }

    /** WALK-04: позапланове прибуття створюється одразу в статусі arrived. */
    public function testWalkInStartsAsArrived(): void
    {
        $booking = BookingFactory::walkIn();

        self::assertSame(BookingStatus::Arrived, $booking->status());
        self::assertSame(BookingType::WalkIn, $booking->type);
        self::assertNotNull($booking->arrivedAt());
        self::assertSame('arrived', $booking->statusHistory()[0]->to->value);
    }

    /** ST-01: водій цього бронювання тисне «На місці». */
    public function testDriverMarksArrived(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');
        $event = $booking->markArrived(new Actor('du-9', Role::Driver), Scenario::kyiv('2026-08-28 09:55'));

        self::assertSame(BookingStatus::Arrived, $booking->status());
        self::assertSame(EventType::BookingArrived, $event->type);
        self::assertNotNull($booking->arrivedAt());
    }

    /** ST-01: магазин теж може відмітити прибуття. */
    public function testStoreOperatorMarksArrived(): void
    {
        $booking = BookingFactory::scheduled();
        $booking->markArrived(new Actor('su-1', Role::StoreOperator, storeId: Scenario::STORE_ID), Scenario::kyiv('2026-08-28 09:55'));

        self::assertSame(BookingStatus::Arrived, $booking->status());
    }

    public function testForeignDriverCannotMarkArrived(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $this->expectException(TransitionNotAllowedException::class);
        $booking->markArrived(new Actor('du-77', Role::Driver), Scenario::kyiv('2026-08-28 09:55'));
    }

    public function testSupplierCannotMarkArrived(): void
    {
        $booking = BookingFactory::scheduled();

        try {
            $booking->markArrived(new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID), Scenario::kyiv('2026-08-28 09:55'));
            self::fail('Постачальник не має права відмічати прибуття');
        } catch (TransitionNotAllowedException $error) {
            self::assertSame('TRANSITION_NOT_ALLOWED', $error->errorCode());
            self::assertSame(403, $error->httpStatus());
        }
    }

    /** ST-02: arrived → unloading виконує магазин. */
    public function testStoreStartsUnloading(): void
    {
        $booking = BookingFactory::walkIn();
        $event = $booking->startUnloading($this->storeActor(), Scenario::kyiv('2026-08-27 09:10'));

        self::assertSame(BookingStatus::Unloading, $booking->status());
        self::assertSame(EventType::UnloadingStarted, $event->type);
        self::assertNotNull($booking->unloadingStartedAt());
    }

    public function testSupplierCannotStartUnloading(): void
    {
        $booking = BookingFactory::walkIn();

        $this->expectException(TransitionNotAllowedException::class);
        $booking->startUnloading(new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID), Scenario::kyiv('2026-08-27 09:10'));
    }

    /** ST-03: unloading → completed, дефолт unloadedPalletsCount = palletsCount. */
    public function testCompleteDefaultsUnloadedPalletsToDeclared(): void
    {
        $booking = $this->unloading();
        $event = $booking->complete($this->storeActor(), Scenario::kyiv('2026-08-27 09:40'));

        self::assertSame(BookingStatus::Completed, $booking->status());
        self::assertSame(4, $booking->unloadedPalletsCount());
        self::assertNull($booking->partialUnload());
        self::assertSame(EventType::UnloadingCompleted, $event->type);
        self::assertSame(4, $event->payload['unloadedPalletsCount']);
    }

    public function testPartialUnloadRequiresReason(): void
    {
        $booking = $this->unloading();

        $this->expectException(ValidationFailedException::class);
        $booking->complete($this->storeActor(), Scenario::kyiv('2026-08-27 09:40'), 2);
    }

    public function testPartialUnloadIsRecordedInEvent(): void
    {
        $booking = $this->unloading();
        $event = $booking->complete(
            $this->storeActor(),
            Scenario::kyiv('2026-08-27 09:40'),
            2,
            new PartialUnload(PartialUnloadReason::NoSpace),
        );

        self::assertSame(2, $booking->unloadedPalletsCount());
        self::assertNotNull($booking->partialUnload());
        self::assertSame('немає місця', $event->payload['partialUnload']['reason']);
        self::assertTrue($event->payload['partialUnload']['flag']);
    }

    public function testPartialUnloadOtherReasonRequiresComment(): void
    {
        $booking = $this->unloading();

        $this->expectException(ValidationFailedException::class);
        $booking->complete(
            $this->storeActor(),
            Scenario::kyiv('2026-08-27 09:40'),
            1,
            new PartialUnload(PartialUnloadReason::Other),
        );
    }

    /** ST-06: перехід поза машиною станів — 409 INVALID_STATUS_TRANSITION. */
    public function testCompletedCannotGoBackToUnloading(): void
    {
        $booking = $this->unloading();
        $booking->complete($this->storeActor(), Scenario::kyiv('2026-08-27 09:40'));

        try {
            $booking->startUnloading($this->storeActor(), Scenario::kyiv('2026-08-27 09:45'));
            self::fail('Перехід completed → unloading має бути заборонений');
        } catch (InvalidStatusTransitionException $error) {
            self::assertSame('INVALID_STATUS_TRANSITION', $error->errorCode());
            self::assertSame(409, $error->httpStatus());
        }
    }

    public function testBookedCannotJumpToUnloading(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(InvalidStatusTransitionException::class);
        $booking->startUnloading($this->storeActor(), Scenario::kyiv('2026-08-28 10:05'));
    }

    /** ST-07: arrived → rejected з обовʼязковою причиною (DATA-32). */
    public function testRejectFillsRejectedAt(): void
    {
        $booking = BookingFactory::walkIn();
        $event = $booking->reject($this->storeActor(), RejectionReason::MissingDocuments, Scenario::kyiv('2026-08-27 09:15'));

        self::assertSame(BookingStatus::Rejected, $booking->status());
        self::assertNotNull($booking->rejection());
        self::assertSame('відсутні документи', $booking->rejection()->reason->value);
        self::assertSame(EventType::BookingRejected, $event->type);
    }

    public function testRejectWithOtherReasonRequiresComment(): void
    {
        $booking = BookingFactory::walkIn();

        $this->expectException(ValidationFailedException::class);
        $booking->reject($this->storeActor(), RejectionReason::Other, Scenario::kyiv('2026-08-27 09:15'));
    }

    public function testCannotRejectBookedBooking(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(InvalidStatusTransitionException::class);
        $booking->reject($this->storeActor(), RejectionReason::CargoMismatch, Scenario::kyiv('2026-08-28 09:00'));
    }

    /** ST-04: постачальник скасовує до дедлайну. */
    public function testSupplierCancelsBeforeDeadline(): void
    {
        $booking = BookingFactory::scheduled();
        $event = $booking->cancel(
            new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID),
            Scenario::kyiv('2026-08-27 12:00'),
            2,
        );

        self::assertSame(BookingStatus::Cancelled, $booking->status());
        self::assertSame('supplier', $booking->cancellation()?->by->value);
        self::assertSame(EventType::BookingCancelled, $event->type);
    }

    public function testCancelAfterArrivedIsInvalidTransition(): void
    {
        $booking = BookingFactory::walkIn();

        $this->expectException(InvalidStatusTransitionException::class);
        $booking->cancel($this->storeActor(), Scenario::kyiv('2026-08-27 09:10'), 2);
    }

    /** NOSH-02: ручний no_show можливий лише після slotEnd. */
    public function testManualNoShowBeforeSlotEndIsRejected(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(ValidationFailedException::class);
        $booking->markNoShow($this->storeActor(), Scenario::kyiv('2026-08-28 10:15'));
    }

    public function testManualNoShowAfterSlotEnd(): void
    {
        $booking = BookingFactory::scheduled();
        $event = $booking->markNoShow($this->storeActor(), Scenario::kyiv('2026-08-28 10:45'));

        self::assertSame(BookingStatus::NoShow, $booking->status());
        self::assertSame(EventType::BookingNoShow, $event->type);
        self::assertFalse($event->payload['auto']);
    }

    /** NOSH-01: cron (системний актор) переводить у no_show автоматично. */
    public function testSystemNoShowIsMarkedAuto(): void
    {
        $booking = BookingFactory::scheduled();
        $event = $booking->markNoShow(Actor::system(), Scenario::kyiv('2026-08-28 11:05'));

        self::assertSame(BookingStatus::NoShow, $booking->status());
        self::assertTrue($event->payload['auto']);
    }

    public function testSystemActorCannotPerformOtherTransitions(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(TransitionNotAllowedException::class);
        $booking->markArrived(Actor::system(), Scenario::kyiv('2026-08-28 09:55'));
    }

    /** DATA-14: кожна зміна статусу дописує запис у statusHistory. */
    public function testStatusHistoryIsAppendOnly(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');
        $booking->markArrived(new Actor('du-9', Role::Driver), Scenario::kyiv('2026-08-28 09:55'));
        $booking->startUnloading($this->storeActor(), Scenario::kyiv('2026-08-28 10:05'));
        $booking->complete($this->storeActor(), Scenario::kyiv('2026-08-28 10:25'));

        $history = $booking->statusHistory();

        self::assertCount(4, $history);
        self::assertSame([null, 'booked', 'arrived', 'unloading'], array_map(
            static fn ($change) => $change->from?->value,
            $history,
        ));
        self::assertSame(['booked', 'arrived', 'unloading', 'completed'], array_map(
            static fn ($change) => $change->to->value,
            $history,
        ));
    }

    /** DLY-01: прапорець затримки не змінює статус і знімається на unloading. */
    public function testDelayFlagIsClearedWhenUnloadingStarts(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');
        $event = $booking->setDelay(
            new Actor('du-9', Role::Driver),
            DelayReason::TrafficJam,
            Scenario::kyiv('2026-08-28 10:40'),
            Scenario::kyiv('2026-08-28 09:30'),
        );

        self::assertSame(BookingStatus::Booked, $booking->status());
        self::assertTrue($booking->delayed()->flag);
        self::assertSame(EventType::BookingDelaySet, $event->type);

        $booking->markArrived(new Actor('du-9', Role::Driver), Scenario::kyiv('2026-08-28 10:45'));
        $booking->startUnloading($this->storeActor(), Scenario::kyiv('2026-08-28 10:50'));

        self::assertFalse($booking->delayed()->flag);
        self::assertNull($booking->delayed()->eta);
    }

    public function testDelayEtaMustBeInFuture(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(ValidationFailedException::class);
        $booking->setDelay(
            $this->storeActor(),
            DelayReason::Breakdown,
            Scenario::kyiv('2026-08-28 09:00'),
            Scenario::kyiv('2026-08-28 09:30'),
        );
    }

    /** EDIT-06: переведення на іншу рампу дозволене лише разово. */
    public function testRampMoveIsAllowedOnlyOnce(): void
    {
        $booking = BookingFactory::scheduled();
        $event = $booking->moveToRamp($this->storeActor(), 'r2', Scenario::kyiv('2026-08-28 09:50'));

        self::assertSame('r2', $booking->rampId());
        self::assertSame(EventType::BookingReassigned, $event->type);
        self::assertSame('r1', $event->payload['previousRampId']);
        self::assertTrue($booking->rampReassigned());

        $this->expectException(ValidationFailedException::class);
        $booking->moveToRamp($this->storeActor(), 'r1', Scenario::kyiv('2026-08-28 09:55'));
    }

    public function testSupplierCannotMoveBookingToAnotherRamp(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(TransitionNotAllowedException::class);
        $booking->moveToRamp(
            new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID),
            'r2',
            Scenario::kyiv('2026-08-28 09:50'),
        );
    }

    private function storeActor(): Actor
    {
        return new Actor('su-1', Role::StoreManager, storeId: Scenario::STORE_ID);
    }

    private function unloading(): \App\Domain\Booking\Booking
    {
        $booking = BookingFactory::walkIn();
        $booking->startUnloading($this->storeActor(), Scenario::kyiv('2026-08-27 09:10'));

        return $booking;
    }
}
