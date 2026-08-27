<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Booking\BookingCreationService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Booking\Exception\VehicleTooHeavyException;
use App\Domain\Exception\ValidationFailedException;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Позапланове прибуття WALK-01..WALK-04.
 */
#[CoversClass(BookingCreationService::class)]
final class WalkInTest extends TestCase
{
    /** WALK-04: walk-in створюється одразу в статусі arrived. */
    public function testWalkInIsCreatedAsArrived(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );

        self::assertSame(BookingStatus::Arrived, $booking->status());
        self::assertSame(BookingType::WalkIn, $booking->type);
        self::assertNotNull($booking->arrivedAt());

        $events = $scenario->outbox->eventsOfType('BookingCreated');

        self::assertCount(1, $events);
        self::assertSame('walk_in', $events[0]->payload['type']);
        self::assertSame('arrived', $events[0]->payload['status']);
    }

    /** WALK-03: lead time GRID-02 до walk-in не застосовується. */
    public function testWalkInIgnoresLeadTime(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:30'),
            $scenario->now(),
        );

        self::assertSame(BookingStatus::Arrived, $booking->status());
    }

    /** WALK-03: слоти в минулому в межах поточної дати допускаються. */
    public function testWalkInAcceptsEarlierSlotOfCurrentDay(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 08:00'),
            $scenario->now(),
        );

        self::assertSame('08:00', $booking->slotStart->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('H:i'));
    }

    /** WALK-03: лише поточна дата. */
    public function testWalkInOnFutureDateIsRejected(): void
    {
        $scenario = new Scenario();

        $this->expectException(ValidationFailedException::class);
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-28 10:00'),
            $scenario->now(),
        );
    }

    public function testWalkInOnPastDateIsRejected(): void
    {
        $scenario = new Scenario();

        $this->expectException(ValidationFailedException::class);
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-26 10:00'),
            $scenario->now(),
        );
    }

    /** WALK-02: постачальник «поза системою» з вільним текстом назви. */
    public function testWalkInAcceptsSupplierOutsideSystem(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00', supplierId: null, supplierName: 'ФОП Іваненко'),
            $scenario->now(),
        );

        self::assertNull($booking->supplierId);
        self::assertSame('ФОП Іваненко', $booking->supplierNameSnapshot);
    }

    public function testWalkInWithoutAnySupplierIsRejected(): void
    {
        $scenario = new Scenario();

        $this->expectException(ValidationFailedException::class);
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00', supplierId: null, supplierName: null),
            $scenario->now(),
        );
    }

    /** WALK-01: право booking.create_walk_in має магазин, а не постачальник. */
    public function testSupplierCannotRegisterWalkIn(): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $scenario->creation->registerWalkIn(
            $scenario->supplier(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );
    }

    public function testNetworkAdminCanRegisterWalkIn(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->registerWalkIn(
            $scenario->networkAdmin(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );

        self::assertSame(BookingType::WalkIn, $booking->type);
    }

    /** WALK-02: перевірка тоннажу BOOK-01 діє і для walk-in. */
    public function testWalkInRespectsWeightLimit(): void
    {
        $scenario = new Scenario();

        $this->expectException(VehicleTooHeavyException::class);
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00', vehicle: Scenario::vehicle(weightTons: 30.0)),
            $scenario->now(),
        );
    }

    /** WALK-03: той самий атомарний ключ слота, що й для планових бронювань. */
    public function testWalkInOnOccupiedSlotIsRejected(): void
    {
        $scenario = new Scenario();
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );

        $this->expectException(SlotAlreadyBookedException::class);
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00', vehicle: Scenario::vehicle('BC9999CT')),
            $scenario->now(),
        );
    }

    /** WALK-04: подальший життєвий цикл звичайний — arrived → unloading → completed. */
    public function testWalkInFollowsRegularLifecycle(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );

        $scenario->lifecycle->startUnloading($scenario->storeStaff(), $booking->id, $scenario->now()->modify('+5 minutes'));
        $completed = $scenario->lifecycle->complete($scenario->storeStaff(), $booking->id, $scenario->now()->modify('+25 minutes'));

        self::assertSame(BookingStatus::Completed, $completed->status());
        self::assertSame(4, $completed->unloadedPalletsCount());
    }
}
