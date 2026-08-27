<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Booking\DriverBookingService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\DelayReason;
use App\Domain\Booking\Exception\TransitionNotAllowedException;
use App\Domain\Booking\RejectionReason;
use App\Domain\Exception\ValidationFailedException;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Межі повноважень ролі `driver` (розділ 8, блок DRV).
 *
 * Водій отримує РІВНО три дії щодо точок ВЛАСНОГО маршрутного листа:
 * відмітку «На місці», повідомлення про затримку і дописування orderId.
 * Усе інше — переходи в unloading/completed/rejected/no_show, скасування,
 * переведення на іншу рампу, walk-in, робота з чужими бронюваннями —
 * лишається недоступним, навіть якщо бронювання призначене саме йому.
 *
 * DRV: «власна точка» означає збіг booking.driverId з ПРОФІЛЕМ водія
 * (X-Driver-Profile-Id), а не з обліковим записом із клейму `sub`.
 */
#[CoversClass(DriverBookingService::class)]
final class DriverContourTest extends TestCase
{
    private const string DRIVER_ID = 'du-1';

    /** ST-01: власна точка — перехід booked → arrived відбувається. */
    public function testDriverMarksArrivalOnOwnBooking(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);

        $arrived = $scenario->driverBookings->markArrived(
            $scenario->driver(self::DRIVER_ID),
            $booking->id,
            Scenario::kyiv('2026-08-28 09:55'),
        );

        self::assertSame('arrived', $arrived->status()->value);
        self::assertCount(1, $scenario->outbox->eventsOfType('BookingArrived'));
    }

    /**
     * Головне правило безпеки: належність до маршрутного листа перевіряється
     * ДО будь-якої дії — чуже бронювання недосяжне для всіх трьох операцій.
     */
    #[DataProvider('driverActions')]
    public function testDriverCannotActOnForeignBooking(string $action): void
    {
        $scenario = new Scenario();
        $foreign = $scenario->book('2026-08-28 10:00', driverId: 'du-2');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Бронювання не входить до маршрутного листа цього водія');

        $this->act($scenario, $action, $foreign->id);
    }

    /** Те саме для бронювання іншого постачальника. */
    #[DataProvider('driverActions')]
    public function testDriverCannotActOnAnotherSuppliersBooking(string $action): void
    {
        $scenario = new Scenario();
        $foreign = $scenario->book(
            '2026-08-28 10:00',
            rampId: 'r2',
            supplierId: Scenario::OTHER_SUPPLIER_ID,
            driverId: 'du-9',
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Бронювання не входить до маршрутного листа цього водія');

        $this->act($scenario, $action, $foreign->id);
    }

    /**
     * DRV: водій без привʼязаного профілю (порожній X-Driver-Profile-Id)
     * не діє взагалі — навіть щодо бронювання, driverId якого збігся з його
     * ОБЛІКОВИМ ЗАПИСОМ. Запасного порівняння з `sub` немає.
     */
    #[DataProvider('driverActions')]
    public function testDriverWithoutProfileCannotActOnAnyBooking(string $action): void
    {
        $scenario = new Scenario();
        $account = Scenario::accountOf(self::DRIVER_ID);
        $booking = $scenario->book('2026-08-28 10:00', driverId: $account);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Бронювання не входить до маршрутного листа цього водія');

        $this->act($scenario, $action, $booking->id, $scenario->driverWithoutProfile($account));
    }

    /**
     * Дзеркальний випадок: те саме бронювання з тим самим водієм, але з
     * профілем у заголовку — і точка вже недосяжна, бо driverId зберігає
     * профіль, а не акаунт.
     */
    public function testProfileIsTheOnlyIdentityComparedWithBookingDriverId(): void
    {
        $scenario = new Scenario();
        $own = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);
        $byAccount = $scenario->book(
            '2026-08-28 10:00',
            rampId: 'r2',
            vehicle: Scenario::vehicle('BC5555CT', 3.5),
            driverId: Scenario::accountOf(self::DRIVER_ID),
        );
        $driver = $scenario->driver(self::DRIVER_ID);

        $arrived = $scenario->driverBookings->markArrived($driver, $own->id, Scenario::kyiv('2026-08-28 09:55'));

        self::assertSame('arrived', $arrived->status()->value);

        $this->expectException(AccessDeniedException::class);
        $scenario->driverBookings->markArrived($driver, $byAccount->id, Scenario::kyiv('2026-08-28 09:55'));
    }

    /** Збіг імен ролі недостатній: водій без призначення — не власник точки. */
    public function testBookingWithoutDriverBelongsToNobody(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $this->expectException(AccessDeniedException::class);
        $scenario->driverBookings->markArrived(
            $scenario->driver(self::DRIVER_ID),
            $booking->id,
            $scenario->now(),
        );
    }

    /** Постачальник і магазин не «водії» власного бронювання. */
    public function testOnlyDriverRolePassesRouteSheetOwnershipCheck(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);

        foreach ([$scenario->supplier(), $scenario->storeStaff(), $scenario->networkAdmin()] as $actor) {
            try {
                $scenario->driverBookings->markArrived($actor, $booking->id, $scenario->now());
                self::fail('Роль «'.$actor->role->value.'» не має проходити перевірку маршрутного листа');
            } catch (AccessDeniedException $error) {
                self::assertSame(403, $error->httpStatus());
            }
        }
    }

    // --- Заборонені повноваження -------------------------------------------

    /**
     * Володіння точкою НЕ дає повноважень магазину: водій свого ж бронювання
     * не переводить його в unloading/completed/rejected/no_show і не скасовує.
     */
    public function testDriverCannotDriveStoreTransitionsOnOwnBooking(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);
        $driver = $scenario->driver(self::DRIVER_ID);
        $now = Scenario::kyiv('2026-08-28 09:55');

        $scenario->lifecycle->markArrived($driver, $booking->id, $now);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->startUnloading($driver, $booking->id, $now);
    }

    public function testDriverCannotCompleteUnloading(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);
        $driver = $scenario->driver(self::DRIVER_ID);
        $now = Scenario::kyiv('2026-08-28 09:55');

        $scenario->lifecycle->markArrived($driver, $booking->id, $now);
        $scenario->lifecycle->startUnloading($scenario->storeStaff(), $booking->id, $now);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->complete($driver, $booking->id, $now);
    }

    public function testDriverCannotRejectOwnBooking(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);
        $driver = $scenario->driver(self::DRIVER_ID);
        $now = Scenario::kyiv('2026-08-28 09:55');

        $scenario->lifecycle->markArrived($driver, $booking->id, $now);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->reject($driver, $booking->id, RejectionReason::MissingDocuments, $now);
    }

    public function testDriverCannotCancelOwnBooking(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->cancel($scenario->driver(self::DRIVER_ID), $booking->id, $scenario->now());
    }

    public function testDriverCannotMarkNoShow(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->markNoShow(
            $scenario->driver(self::DRIVER_ID),
            $booking->id,
            Scenario::kyiv('2026-08-28 11:00'),
        );
    }

    public function testDriverCannotMoveBookingToAnotherRamp(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);

        $this->expectException(TransitionNotAllowedException::class);
        $scenario->lifecycle->moveToRamp($scenario->driver(self::DRIVER_ID), $booking->id, 'r2', $scenario->now());
    }

    public function testDriverCannotRegisterWalkIn(): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $scenario->creation->registerWalkIn(
            $scenario->driver(self::DRIVER_ID),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );
    }

    /** Навіть із заповненим X-Store-Ids водій не отримує повноважень магазину. */
    public function testDriverWithStoreScopeHeaderStillHasNoStorePowers(): void
    {
        $scenario = new Scenario();
        $driverWithStores = new Actor(
            Scenario::accountOf(self::DRIVER_ID),
            Role::Driver,
            storeIds: [Scenario::STORE_ID],
            driverProfileId: self::DRIVER_ID,
        );

        $this->expectException(AccessDeniedException::class);
        $scenario->creation->registerWalkIn(
            $driverWithStores,
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );
    }

    public function testDriverCannotCreateScheduledBooking(): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $scenario->creation->create($scenario->driver(self::DRIVER_ID), $scenario->request(), $scenario->now());
    }

    public function testDriverCannotAssignDriversToRouteSheet(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');

        $this->expectException(AccessDeniedException::class);
        $scenario->routeSheets->assignDriverToSheet(
            $scenario->driver(self::DRIVER_ID),
            Scenario::SUPPLIER_ID,
            '2026-08-28',
            self::DRIVER_ID,
            $scenario->now(),
        );
    }

    /** Знімає прапорець затримки лише магазин або система (DLY-01). */
    public function testDriverCannotClearDelayFlag(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);
        $driver = $scenario->driver(self::DRIVER_ID);

        $scenario->driverBookings->reportDelay(
            $driver,
            $booking->id,
            DelayReason::TrafficJam,
            Scenario::kyiv('2026-08-28 10:40'),
            $scenario->now(),
        );

        $this->expectException(ValidationFailedException::class);
        $scenario->reload($booking)->clearDelay($driver, $scenario->now());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function driverActions(): iterable
    {
        yield 'відмітка «На місці»' => ['arrived'];
        yield 'повідомлення про затримку' => ['delay'];
        yield 'дописування orderId' => ['orderId'];
    }

    private function act(Scenario $scenario, string $action, string $bookingId, ?Actor $actor = null): void
    {
        $driver = $actor ?? $scenario->driver(self::DRIVER_ID);
        $now = $scenario->now();

        match ($action) {
            'arrived' => $scenario->driverBookings->markArrived($driver, $bookingId, $now),
            'delay' => $scenario->driverBookings->reportDelay(
                $driver,
                $bookingId,
                DelayReason::TrafficJam,
                Scenario::kyiv('2026-08-28 10:40'),
                $now,
            ),
            'orderId' => $scenario->driverBookings->updateOrderId($driver, $bookingId, '4410233', $now),
        };
    }
}
