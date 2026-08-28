<?php

declare(strict_types=1);

namespace App\Tests\Domain\Booking;

use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\ArrivalWindow;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\Exception\ArrivalTooEarlyException;
use App\Domain\Event\EventType;
use App\Domain\Store\StorePolicy;
use App\Tests\Support\BookingFactory;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Доступність відмітки «На місці» за часом (розділ 8, D-04, ISSUE-13).
 *
 * Вікно описане в ArrivalWindow і має рівно дві межі:
 *   раніше за slotStart − ARRIVAL_WINDOW_MINUTES → відмова ARRIVAL_TOO_EARLY;
 *   від цієї межі до кінця слоту                 → вчасне прибуття;
 *   після кінця слоту                            → приймається, late = true.
 *
 * Слот у BookingFactory — 2026-08-28 10:00…10:30 за Києвом, тож вікно
 * відкривається о 09:00.
 */
#[CoversClass(ArrivalWindow::class)]
#[CoversClass(ArrivalTooEarlyException::class)]
#[CoversClass(\App\Domain\Booking\Booking::class)]
final class ArrivalWindowTest extends TestCase
{
    /** Ширина вікна живе в одному місці — домен бере її звідти. */
    public function testWindowWidthComesFromThePolicyConstant(): void
    {
        $window = self::window();

        self::assertSame(60, StorePolicy::ARRIVAL_WINDOW_MINUTES);
        self::assertSame(
            Scenario::kyiv('2026-08-28 10:00')
                ->modify(\sprintf('-%d minutes', StorePolicy::ARRIVAL_WINDOW_MINUTES))
                ->getTimestamp(),
            $window->opensAt->getTimestamp(),
        );
    }

    /**
     * Головне: задовго до слоту відмітка не проходить. Саме такий дотик
     * ставив у чергу магазину машину, якої немає, і водій не міг це відкотити.
     */
    public function testArrivalBeforeWindowOpensIsRejected(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $this->expectException(ArrivalTooEarlyException::class);

        $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 06:00'));
    }

    /** За добу — тим паче. */
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
            $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 08:59'));
            self::fail('Відмітка до відкриття вікна мала бути відхилена');
        } catch (ArrivalTooEarlyException $error) {
            self::assertSame('ARRIVAL_TOO_EARLY', $error->errorCode());
            self::assertSame(422, $error->httpStatus());
            // Повідомлення мусить називати ЧАС, коли відмітка стане доступною.
            self::assertStringContainsString('з 09:00', $error->getMessage());
            self::assertStringContainsString('слот о 10:00', $error->getMessage());
            self::assertSame('09:00', $error->problemExtensions()['localOpensAt']);
            self::assertSame('2026-08-28T06:00:00Z', $error->problemExtensions()['opensAt']);
        }

        self::assertSame(BookingStatus::Booked, $booking->status());
        self::assertNull($booking->arrivedAt());
        self::assertCount(1, $booking->statusHistory());
    }

    /** Сьогоднішня подія — дату не називаємо, вона лише заважає. */
    public function testMessageNamesOnlyTimeWhenWindowOpensToday(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        try {
            $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 07:30'));
            self::fail('Очікувалася відмова');
        } catch (ArrivalTooEarlyException $error) {
            self::assertStringNotContainsString('28.08.2026', $error->getMessage());
        }
    }

    /** А для чужої доби дата обовʼязкова — інакше «з 09:00» ні про що. */
    public function testMessageNamesDateWhenWindowOpensAnotherDay(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        try {
            $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-27 09:55'));
            self::fail('Очікувалася відмова');
        } catch (ArrivalTooEarlyException $error) {
            self::assertStringContainsString('28.08.2026', $error->getMessage());
        }
    }

    /**
     * Заборона доменна, тож діє в обох контурах: магазин так само не має
     * ставити в чергу машину, яку чекають лише ввечері.
     */
    public function testStoreIsBoundByTheSameWindow(): void
    {
        $booking = BookingFactory::scheduled();

        $this->expectException(ArrivalTooEarlyException::class);

        $booking->markArrived($this->storeStaff(), Scenario::kyiv('2026-08-28 06:00'));
    }

    /** Рівно на межі −60 хв вікно вже відкрите. */
    public function testArrivalExactlyAtWindowOpeningIsAccepted(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $event = $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 09:00'));

        self::assertSame(BookingStatus::Arrived, $booking->status());
        self::assertSame(EventType::BookingArrived, $event->type);
        self::assertFalse($event->payload['late']);
        self::assertFalse($booking->arrivedLate());
    }

    /** Прибуття після кінця слоту приймається з позначкою запізнення. */
    public function testArrivalAfterSlotEndIsMarkedLate(): void
    {
        $booking = BookingFactory::scheduled(driverId: 'du-9');

        $event = $booking->markArrived($this->driver(), Scenario::kyiv('2026-08-28 11:20'));

        self::assertSame(BookingStatus::Arrived, $booking->status());
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
        $window = self::window();

        self::assertSame('2026-08-28', $window->localDate);
        self::assertSame('09:00', $window->localOpensAt);
        self::assertSame('10:00', $window->localSlotTime);
        self::assertTrue($window->isBeforeOpening(Scenario::kyiv('2026-08-28 08:59')));
        self::assertFalse($window->isBeforeOpening(Scenario::kyiv('2026-08-28 09:00')));
        self::assertFalse($window->isLate(Scenario::kyiv('2026-08-28 10:30')));
        self::assertTrue($window->isLate(Scenario::kyiv('2026-08-28 10:31')));
        self::assertTrue($window->isSameLocalDay(Scenario::kyiv('2026-08-28 00:10')));
        self::assertFalse($window->isSameLocalDay(Scenario::kyiv('2026-08-27 23:50')));
    }

    private static function window(): ArrivalWindow
    {
        return ArrivalWindow::forSlot(
            Scenario::kyiv('2026-08-28 10:00'),
            Scenario::kyiv('2026-08-28 10:30'),
        );
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
