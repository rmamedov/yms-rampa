<?php

declare(strict_types=1);

namespace App\Tests\Domain\Booking;

use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\ArrivalWindow;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\Exception\ArrivalTooEarlyException;
use App\Domain\Event\EventType;
use App\Tests\Support\BookingFactory;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Доступність відмітки «На місці» за часом (розділ 8, D-04, ISSUE-13).
 *
 * Вікно описане в ArrivalWindow:
 *   раніше за добу візиту          → відмова ARRIVAL_TOO_EARLY;
 *   доба візиту, але до −60 хв     → приймається, punctuality=early;
 *   від −60 хв до кінця слоту      → punctuality=on_time;
 *   після кінця слоту              → приймається з позначкою запізнення.
 *
 * Слот у BookingFactory — 2026-08-28 10:00…10:30 за Києвом.
 */
#[CoversClass(ArrivalWindow::class)]
#[CoversClass(ArrivalTooEarlyException::class)]
#[CoversClass(\App\Domain\Booking\Booking::class)]
final class ArrivalWindowTest extends TestCase
{
    /**
     * Головне: за добу до слоту відмітка не проходить. Саме цей дотик ставив
     * у чергу магазину машину, якої немає, і водій не міг це відкотити.
     */
    public function testArrivalTheDayBeforeIsRejected(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $this->expectException(ArrivalTooEarlyException::class);

        $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-27 09:55'));
    }

    /** Відмова не змінює нічого: статус лишається booked, історія — чистою. */
    public function testRejectedArrivalLeavesBookingUntouched(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        try {
            $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-27 23:59'));
            self::fail('Відмітка за добу до слоту мала бути відхилена');
        } catch (ArrivalTooEarlyException $error) {
            self::assertSame('ARRIVAL_TOO_EARLY', $error->errorCode());
            self::assertSame(422, $error->httpStatus());
            // Повідомлення мусить називати, коли відмітка стане доступною.
            self::assertStringContainsString('28.08.2026', $error->getMessage());
            self::assertSame('2026-08-28', $error->problemExtensions()['localDate']);
        }

        self::assertSame(BookingStatus::Booked, $booking->status());
        self::assertNull($booking->arrivedAt());
        self::assertCount(1, $booking->statusHistory());
    }

    /**
     * Заборона доменна, тож діє в обох контурах: магазин так само не має
     * ставити в чергу машину, яку чекають лише завтра.
     */
    public function testStoreIsBoundByTheSameWindow(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(ArrivalTooEarlyException::class);

        $booking->markArrived($this->storeStaff(), Scenario::kyiv('2026-08-27 09:55'));
    }

    /**
     * Доба візиту вже настала — відмітка проходить, навіть якщо до слоту ще
     * далеко: маршрутний лист водій відкриває вранці, а точки в ньому — на
     * весь день. Позначка `early` лишає цей факт видимим для магазину.
     */
    public function testEarlyArrivalOnTheDayOfVisitIsAcceptedAndFlagged(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $event = $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 03:10'));

        self::assertSame(BookingStatus::Arrived, $booking->status());
        self::assertSame(EventType::BookingArrived, $event->type);
        self::assertSame(ArrivalWindow::EARLY, $event->payload['punctuality']);
        self::assertFalse($event->payload['late']);
        self::assertFalse($booking->arrivedLate());
    }

    /** Рівно на межі −60 хв вікно вже відкрите. */
    public function testArrivalExactlyAtWindowOpeningIsOnTime(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $event = $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 09:00'));

        self::assertSame(ArrivalWindow::ON_TIME, $event->payload['punctuality']);
    }

    /** Прибуття після кінця слоту приймається з позначкою запізнення. */
    public function testArrivalAfterSlotEndIsMarkedLate(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $event = $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 11:20'));

        self::assertSame(BookingStatus::Arrived, $booking->status());
        self::assertSame(ArrivalWindow::LATE, $event->payload['punctuality']);
        self::assertTrue($event->payload['late']);
        self::assertTrue($booking->arrivedLate());
    }

    /** Вчасне прибуття запізненням не позначається. */
    public function testOnTimeArrivalIsNotLate(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 10:05'));

        self::assertFalse($booking->arrivedLate());
    }

    /** Межі вікна рахуються за календарем магазину (Europe/Kyiv). */
    public function testWindowBoundaries(): void
    {
        $window = ArrivalWindow::forSlot(
            Scenario::kyiv('2026-08-28 10:00'),
            Scenario::kyiv('2026-08-28 10:30'),
        );

        self::assertSame('2026-08-28', $window->localDate);
        self::assertSame('10:00', $window->localSlotTime);
        self::assertTrue($window->isBeforeDayOfVisit(Scenario::kyiv('2026-08-27 23:59')));
        self::assertFalse($window->isBeforeDayOfVisit(Scenario::kyiv('2026-08-28 00:00')));
        self::assertTrue($window->isEarly(Scenario::kyiv('2026-08-28 08:59')));
        self::assertFalse($window->isEarly(Scenario::kyiv('2026-08-28 09:00')));
        self::assertFalse($window->isLate(Scenario::kyiv('2026-08-28 10:30')));
        self::assertTrue($window->isLate(Scenario::kyiv('2026-08-28 10:31')));
    }

    private function driver(): Actor
    {
        return new Actor('du-user', Role::Driver, driverProfileId: 'du-9');
    }

    private function storeStaff(): Actor
    {
        return new Actor('su-1', Role::StoreOperator, storeIds: [Scenario::STORE_ID]);
    }
}
