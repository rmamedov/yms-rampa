<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Application\RouteSheet\RouteSheetService;
use App\Application\Slot\SlotGridService;
use App\Domain\Access\Actor;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\DelayReason;
use App\Domain\Booking\Exception\BookingNotFoundException;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Booking\PartialUnload;
use App\Domain\Booking\RejectionReason;
use App\Domain\Booking\VehicleSnapshot;
use App\Domain\Slot\SlotKey;
use DateTimeImmutable;

/**
 * Переходи статусів ST-01..ST-07 і зміни бронювання без зміни слота
 * (EDIT-02..EDIT-06, DLY-01).
 *
 * Правила переходів і прав живуть в агрегаті Booking; тут — завантаження,
 * конфігурація магазину та збереження разом із подіями (transactional outbox).
 */
final readonly class BookingLifecycleService
{
    public function __construct(
        private BookingRepository $bookings,
        private SlotGridService $grid,
        private RouteSheetService $routeSheets,
    ) {
    }

    /** ST-01: booked → arrived. */
    public function markArrived(Actor $actor, string $bookingId, DateTimeImmutable $now): Booking
    {
        $booking = $this->load($bookingId);
        $this->bookings->save($booking, [$booking->markArrived($actor, $now)]);

        return $booking;
    }

    /** ST-02: arrived → unloading. */
    public function startUnloading(Actor $actor, string $bookingId, DateTimeImmutable $now): Booking
    {
        $booking = $this->load($bookingId);
        $this->bookings->save($booking, [$booking->startUnloading($actor, $now)]);

        return $booking;
    }

    /** ST-03: unloading → completed з фактично розвантаженими палетами. */
    public function complete(
        Actor $actor,
        string $bookingId,
        DateTimeImmutable $now,
        ?int $unloadedPalletsCount = null,
        ?PartialUnload $partialUnload = null,
    ): Booking {
        $booking = $this->load($bookingId);
        $this->bookings->save($booking, [
            $booking->complete($actor, $now, $unloadedPalletsCount, $partialUnload),
        ]);

        return $booking;
    }

    /** ST-07: arrived → rejected з обовʼязковою причиною з довідника. */
    public function reject(
        Actor $actor,
        string $bookingId,
        RejectionReason $reason,
        DateTimeImmutable $now,
        ?string $comment = null,
    ): Booking {
        $booking = $this->load($bookingId);
        $this->bookings->save($booking, [$booking->reject($actor, $reason, $now, $comment)]);
        $this->routeSheets->syncForBooking($booking);

        return $booking;
    }

    /** NOSH-02: ручна позначка «не приїхав» магазином після slotEnd. */
    public function markNoShow(Actor $actor, string $bookingId, DateTimeImmutable $now): Booking
    {
        $booking = $this->load($bookingId);
        $this->bookings->save($booking, [
            $booking->markNoShow($actor, $now),
            $booking->slotReleasedEvent($now),
        ]);
        $this->routeSheets->syncForBooking($booking);

        return $booking;
    }

    /**
     * ST-04 + EDIT-03: скасування миттєво повертає слот у пул
     * (подія SlotReleased) і виключає точку з маршрутного листа.
     */
    public function cancel(Actor $actor, string $bookingId, DateTimeImmutable $now, ?string $reason = null): Booking
    {
        $booking = $this->load($bookingId);
        $settings = $this->grid->settingsFor($booking->storeId, $actor);

        $this->bookings->save($booking, [
            $booking->cancel($actor, $now, $settings->policy->editDeadlineHours, $reason),
            $booking->slotReleasedEvent($now),
        ]);
        $this->routeSheets->syncForBooking($booking);

        return $booking;
    }

    /** DLY-01: прапорець затримки з причиною та ETA; статус не змінюється. */
    public function setDelay(
        Actor $actor,
        string $bookingId,
        DelayReason $reason,
        DateTimeImmutable $eta,
        DateTimeImmutable $now,
        ?string $comment = null,
    ): Booking {
        $booking = $this->load($bookingId);
        $this->bookings->save($booking, [$booking->setDelay($actor, $reason, $eta, $now, $comment)]);

        return $booking;
    }

    /**
     * EDIT-06: разове переведення бронювання на іншу вільну рампу
     * того самого часового слота.
     */
    public function moveToRamp(Actor $actor, string $bookingId, string $rampId, DateTimeImmutable $now): Booking
    {
        $booking = $this->load($bookingId);
        $targetKey = new SlotKey($booking->storeId, $rampId, $booking->slotStart);

        if (null !== $this->bookings->findActiveBySlotKey($targetKey)) {
            throw new SlotAlreadyBookedException($targetKey);
        }

        $this->bookings->save($booking, [
            $booking->moveToRamp($actor, $rampId, $now),
            // Стара рампа звільняється для інших бронювань цього слота.
            $booking->slotReleasedEvent($now),
        ]);

        return $booking;
    }

    /**
     * EDIT-05: зміна водія та/або авто без зміни слота — дозволена
     * до статусу arrived незалежно від editDeadlineHours, з повторною
     * перевіркою тоннажу BOOK-01.
     */
    public function reassign(
        Actor $actor,
        string $bookingId,
        DateTimeImmutable $now,
        ?string $driverId = null,
        ?VehicleSnapshot $vehicle = null,
        bool $driverProvided = false,
    ): Booking {
        $booking = $this->load($bookingId);
        $settings = $this->grid->settingsFor($booking->storeId, $actor);

        $event = $booking->reassign(
            actor: $actor,
            now: $now,
            maxVehicleWeightTons: $settings->config->maxVehicleWeightTons,
            driverId: $driverId,
            vehicle: $vehicle,
            driverProvided: $driverProvided,
        );

        $this->bookings->save($booking, [$event]);

        if ($driverProvided && null !== $booking->supplierId) {
            // Маршрутні листи обох водіїв оновлюються негайно (RSHT-02).
            $this->routeSheets->assignDriverToBooking($actor, $bookingId, $driverId, $now);
        }

        return $booking;
    }

    /** EDIT-04: редагування orderId і palletsCount до статусу arrived. */
    public function updateDetails(
        Actor $actor,
        string $bookingId,
        DateTimeImmutable $now,
        ?string $orderId = null,
        ?int $palletsCount = null,
        bool $orderIdProvided = false,
    ): Booking {
        $booking = $this->load($bookingId);
        $settings = $this->grid->settingsFor($booking->storeId, $actor);

        $booking->updateDetails(
            actor: $actor,
            now: $now,
            editDeadlineHours: $settings->policy->editDeadlineHours,
            orderId: $orderId,
            palletsCount: $palletsCount,
            orderIdProvided: $orderIdProvided,
        );

        $this->bookings->save($booking, []);

        return $booking;
    }

    public function load(string $bookingId): Booking
    {
        $booking = $this->bookings->find($bookingId);

        if (null === $booking) {
            throw new BookingNotFoundException($bookingId);
        }

        return $booking;
    }
}
