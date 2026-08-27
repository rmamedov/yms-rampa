<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Booking\NoShowSweeper;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\DelayReason;
use App\Domain\Store\StorePolicy;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Авто-no_show NOSH-01 разом із винятком для бронювань з позначкою затримки.
 *
 * Слот у тестах: 2026-08-28 10:00–10:30 (Europe/Kyiv), grace 30 хв,
 * тобто поріг за замовчуванням — 11:00.
 */
#[CoversClass(NoShowSweeper::class)]
final class NoShowSweeperTest extends TestCase
{
    /** NOSH-01: після slotEnd + grace бронювання переходить у no_show. */
    public function testOverdueBookingIsMarkedNoShow(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $swept = $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:05'));

        self::assertCount(1, $swept);
        self::assertSame(BookingStatus::NoShow, $scenario->reload($booking)->status());
    }

    /** NOSH-01: у межах grace бронювання не чіпається. */
    public function testBookingWithinGracePeriodIsUntouched(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $swept = $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 10:45'));

        self::assertSame([], $swept);
        self::assertSame(BookingStatus::Booked, $scenario->reload($booking)->status());
    }

    /** NOSH-01: grace налаштовується на магазин. */
    public function testGraceMinutesComeFromStoreConfiguration(): void
    {
        $scenario = new Scenario(settings: Scenario::storeSettings(
            policy: new StorePolicy(noShowGraceMinutes: 90),
        ));
        $booking = $scenario->book('2026-08-28 10:00');

        self::assertSame([], $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:30')));
        self::assertCount(1, $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 12:05')));
        self::assertSame(BookingStatus::NoShow, $scenario->reload($booking)->status());
    }

    /** NOSH-01: delayed=true з ETA в майбутньому виключає з авто-no_show. */
    public function testDelayedBookingWithFutureEtaIsSkipped(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $scenario->lifecycle->setDelay(
            $scenario->storeStaff(),
            $booking->id,
            DelayReason::TrafficJam,
            Scenario::kyiv('2026-08-28 12:00'),
            Scenario::kyiv('2026-08-28 10:40'),
        );

        $swept = $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:05'));

        self::assertSame([], $swept);
        self::assertSame(BookingStatus::Booked, $scenario->reload($booking)->status());
    }

    /** NOSH-01: якщо ETA минув без arrived — бронювання переходить у no_show. */
    public function testDelayedBookingIsSweptAfterEtaPlusGrace(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $scenario->lifecycle->setDelay(
            $scenario->storeStaff(),
            $booking->id,
            DelayReason::PreviousStop,
            Scenario::kyiv('2026-08-28 12:00'),
            Scenario::kyiv('2026-08-28 10:40'),
        );

        self::assertSame([], $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 12:20')));
        self::assertCount(1, $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 12:35')));
        self::assertSame(BookingStatus::NoShow, $scenario->reload($booking)->status());
    }

    /** NOSH-01: оновлення ETA зсуває поріг. */
    public function testUpdatingEtaShiftsThreshold(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $scenario->lifecycle->setDelay(
            $scenario->storeStaff(),
            $booking->id,
            DelayReason::TrafficJam,
            Scenario::kyiv('2026-08-28 11:30'),
            Scenario::kyiv('2026-08-28 10:40'),
        );
        $scenario->lifecycle->setDelay(
            $scenario->storeStaff(),
            $booking->id,
            DelayReason::TrafficJam,
            Scenario::kyiv('2026-08-28 13:00'),
            Scenario::kyiv('2026-08-28 11:50'),
        );

        self::assertSame([], $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 12:15')));
        self::assertSame(BookingStatus::Booked, $scenario->reload($booking)->status());
    }

    /** NOSH-01: бронювання з позначкою «На місці» в кандидати не потрапляє. */
    public function testArrivedBookingIsNeverSwept(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->lifecycle->markArrived($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 09:58'));

        $swept = $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:05'));

        self::assertSame([], $swept);
        self::assertSame(BookingStatus::Arrived, $scenario->reload($booking)->status());
    }

    /** NOSH-01: публікуються BookingNoShow і SlotReleased. */
    public function testSweepPublishesCanonicalEvents(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');
        $scenario->outbox->clear();

        $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:05'));

        self::assertSame(['BookingNoShow', 'SlotReleased'], $scenario->outbox->eventTypes());
        self::assertTrue($scenario->outbox->eventsOfType('BookingNoShow')[0]->payload['auto']);
    }

    /** NOSH-01: слот після авто-no_show знову доступний. */
    public function testSlotBecomesFreeAfterNoShow(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');

        $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:05'));

        self::assertNull($scenario->bookings->findActiveBySlotKey($scenario->slotKey('2026-08-28 10:00')));
    }

    public function testSweepIsIdempotent(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');

        $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:05'));

        self::assertSame([], $scenario->sweeper->sweep(Scenario::kyiv('2026-08-28 11:30')));
    }
}
