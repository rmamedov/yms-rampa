<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Application\RouteSheet\RouteSheetService;
use App\Application\Slot\SlotGridService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\Exception\BookingLimitExceededException;
use App\Domain\Booking\Exception\BookingNotFoundException;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Booking\Exception\SlotNotAvailableException;
use App\Domain\Booking\Exception\SlotReservedException;
use App\Domain\Booking\Exception\VehicleTimeConflictException;
use App\Domain\Booking\VehicleSnapshot;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Hold\Exception\SlotHeldException;
use App\Domain\Hold\SlotHoldStore;
use App\Domain\Shared\IdGenerator;
use App\Domain\Slot\SlotKey;
use App\Domain\Slot\SlotState;
use App\Domain\Store\StoreSettings;
use App\Domain\Supplier\SupplierDirectory;
use DateTimeImmutable;

/**
 * Створення бронювань: планових (BOOK-01..BOOK-09), позапланових
 * walk-in (WALK-01..WALK-04) та перенесення слота (EDIT-01).
 */
final readonly class BookingCreationService
{
    public function __construct(
        private SlotGridService $grid,
        private BookingRepository $bookings,
        private SlotHoldStore $holds,
        private SupplierDirectory $suppliers,
        private RouteSheetService $routeSheets,
        private IdGenerator $ids,
    ) {
    }

    /**
     * BOOK-06: успішне бронювання створює документ зі статусом `booked`
     * і `type=scheduled`, знімає hold і публікує подію `BookingCreated`.
     */
    public function create(Actor $actor, NewBookingRequest $request, DateTimeImmutable $now): Booking
    {
        if (!$actor->role->isSupplier()) {
            throw new AccessDeniedException('Створювати планові бронювання може лише кабінет постачальника');
        }

        $supplierId = (string) $actor->supplierId;
        $settings = $this->grid->settingsFor($request->storeId, $actor);

        // BOOK-02: постачальник активний і має доступ до цієї філії.
        $supplier = $this->suppliers->assertMayBookAt($supplierId, $request->storeId);

        // BOOK-01: перевірка тоннажу обовʼязково повторюється на сервері.
        $request->vehicle->assertFitsStoreLimit($settings->config->maxVehicleWeightTons);
        Booking::assertPalletsInRange($request->palletsCount);

        $slotKey = new SlotKey($request->storeId, $request->rampId, $request->slotStart);
        $slotEnd = $request->slotStart->modify(\sprintf('+%d minutes', $settings->config->slotSizeMinutes));

        // BOOK-03: повторна серверна перевірка минулого, lead time і горизонту.
        $this->assertSlotBookable($settings, $slotKey, $request->holdToken, $now, $actor->supplierId);

        // BOOK-09: анти-сквотинг.
        $this->assertSupplierLimit($settings, $supplierId, $now);

        // BOOK-04: попередження про перетин за одним держномером.
        if (!$request->confirmConflict) {
            $this->assertNoVehicleConflict($supplierId, $request->vehicle, $request->slotStart, $slotEnd);
        }

        $bookingId = $this->ids->generate();
        $localDate = SlotGridService::localDate($request->slotStart);
        $sheet = $this->routeSheets->ensureSheet($supplierId, $localDate);

        $booking = Booking::schedule(
            id: $bookingId,
            storeId: $request->storeId,
            storeSnapshot: $settings->snapshot,
            rampId: $request->rampId,
            slotStart: $request->slotStart,
            slotEnd: $slotEnd,
            supplierId: $supplierId,
            supplierNameSnapshot: $supplier->name,
            vehicle: $request->vehicle,
            palletsCount: $request->palletsCount,
            createdBy: $actor,
            now: $now,
            driverId: $request->driverId,
            orderId: $request->orderId,
        );
        $booking->attachToRouteSheet($sheet->id);

        // BOOK-07/BOOK-08: атомарна вставка з унікальним індексом на ключ слота.
        $this->bookings->insertIfSlotFree($booking, [$booking->bookingCreatedEvent($now)]);

        $this->routeSheets->sync($supplierId, $localDate);

        if (null !== $request->holdToken) {
            $this->holds->release($slotKey, $request->holdToken);
        }

        return $booking;
    }

    /**
     * WALK-01..WALK-04: реєстрація позапланового прибуття магазином.
     * Створюється одразу у статусі `arrived`, lead time не застосовується,
     * у ліміт постачальника BOOK-09 не входить.
     */
    public function registerWalkIn(Actor $actor, WalkInRequest $request, DateTimeImmutable $now): Booking
    {
        if (!$actor->canOperateStore($request->storeId)) {
            throw AccessDeniedException::forWalkIn();
        }

        $settings = $this->grid->settingsFor($request->storeId, $actor);
        $today = SlotGridService::localDate($now);
        $slotDate = SlotGridService::localDate($request->slotStart);

        // WALK-03: лише поточна дата свого магазину.
        if ($slotDate !== $today) {
            throw new ValidationFailedException(
                'Позапланове прибуття реєструється лише на слот поточної дати'
            );
        }

        $request->vehicle->assertFitsStoreLimit($settings->config->maxVehicleWeightTons);
        Booking::assertPalletsInRange($request->palletsCount);

        if (null === $request->supplierId && (null === $request->supplierName || '' === trim($request->supplierName))) {
            throw new ValidationFailedException(
                'Вкажіть постачальника зі списку або назву постачальника «поза системою»'
            );
        }

        $slotKey = new SlotKey($request->storeId, $request->rampId, $request->slotStart);
        $slotEnd = $request->slotStart->modify(\sprintf('+%d minutes', $settings->config->slotSizeMinutes));

        $grid = $this->grid->buildForWalkIn($settings, $slotDate, $now);
        $slot = $this->grid->findSlot($grid, $slotKey);

        if (null === $slot) {
            throw SlotNotAvailableException::outsideGrid();
        }

        if (SlotState::Booked === $slot->state) {
            throw new SlotAlreadyBookedException($slotKey);
        }

        if (SlotState::Available !== $slot->state) {
            throw new SlotNotAvailableException(
                $slot->state,
                'Позапланове прибуття можна зареєструвати лише на вільний слот',
            );
        }

        $supplierName = null !== $request->supplierId
            ? ($this->suppliers->find($request->supplierId)?->name ?? (string) $request->supplierName)
            : trim((string) $request->supplierName);

        $booking = Booking::walkIn(
            id: $this->ids->generate(),
            storeId: $request->storeId,
            storeSnapshot: $settings->snapshot,
            rampId: $request->rampId,
            slotStart: $request->slotStart,
            slotEnd: $slotEnd,
            supplierId: $request->supplierId,
            supplierNameSnapshot: $supplierName,
            vehicle: $request->vehicle,
            palletsCount: $request->palletsCount,
            createdBy: $actor,
            now: $now,
            orderId: $request->orderId,
        );

        $this->bookings->insertIfSlotFree($booking, [$booking->bookingCreatedEvent($now)]);

        return $booking;
    }

    /**
     * EDIT-01: перенесення слота — атомарна операція «нове бронювання +
     * скасування старого». Публікується пара подій `BookingCreated`
     * (з `rescheduleOf`) і `BookingCancelled` плюс `SlotReleased`.
     * Якщо новий слот зайнятий — старе бронювання лишається недоторканим.
     */
    public function reschedule(
        Actor $actor,
        string $bookingId,
        NewBookingRequest $request,
        DateTimeImmutable $now,
    ): Booking {
        $old = $this->bookings->find($bookingId);

        if (null === $old) {
            throw new BookingNotFoundException($bookingId);
        }

        if (!$actor->role->isSupplier() || !$actor->belongsToSupplier((string) $old->supplierId)) {
            throw AccessDeniedException::foreignBooking();
        }

        if (!$old->status()->isActive()) {
            throw new ValidationFailedException('Переносити можна лише активне бронювання');
        }

        $oldSettings = $this->grid->settingsFor($old->storeId, $actor);
        // EDIT-02: дедлайн змін рахується від слоту, який переносять.
        $old->assertEditDeadline($now, $oldSettings->policy->editDeadlineHours);

        $supplierId = (string) $actor->supplierId;
        $settings = $this->grid->settingsFor($request->storeId, $actor);
        $supplier = $this->suppliers->assertMayBookAt($supplierId, $request->storeId);

        $request->vehicle->assertFitsStoreLimit($settings->config->maxVehicleWeightTons);
        Booking::assertPalletsInRange($request->palletsCount);

        $slotKey = new SlotKey($request->storeId, $request->rampId, $request->slotStart);
        $slotEnd = $request->slotStart->modify(\sprintf('+%d minutes', $settings->config->slotSizeMinutes));

        $this->assertSlotBookable($settings, $slotKey, $request->holdToken, $now, $actor->supplierId);

        if (!$request->confirmConflict) {
            $this->assertNoVehicleConflict($supplierId, $request->vehicle, $request->slotStart, $slotEnd, $bookingId);
        }

        $localDate = SlotGridService::localDate($request->slotStart);
        $sheet = $this->routeSheets->ensureSheet($supplierId, $localDate);

        $new = Booking::schedule(
            id: $this->ids->generate(),
            storeId: $request->storeId,
            storeSnapshot: $settings->snapshot,
            rampId: $request->rampId,
            slotStart: $request->slotStart,
            slotEnd: $slotEnd,
            supplierId: $supplierId,
            supplierNameSnapshot: $supplier->name,
            vehicle: $request->vehicle,
            palletsCount: $request->palletsCount,
            createdBy: $actor,
            now: $now,
            driverId: $request->driverId ?? $old->driverId(),
            orderId: $request->orderId ?? $old->orderId(),
            rescheduleOf: $old->id,
        );
        $new->attachToRouteSheet($sheet->id);

        $cancelEvent = $old->cancel(
            actor: $actor,
            now: $now,
            editDeadlineHours: $oldSettings->policy->editDeadlineHours,
            reason: 'перенесення слота',
            rescheduledTo: $new->id,
        );

        $this->bookings->reschedule($new, $old, [
            $new->bookingCreatedEvent($now),
            $cancelEvent,
            $old->slotReleasedEvent($now),
        ]);

        $this->routeSheets->syncForBooking($old);
        $this->routeSheets->sync($supplierId, $localDate);

        if (null !== $request->holdToken) {
            $this->holds->release($slotKey, $request->holdToken);
        }

        return $new;
    }

    /**
     * Перевірка стану слота на момент підтвердження (GRID-01 кроки 5–10,
     * BOOK-03, BOOK-05, HOLD-03).
     */
    private function assertSlotBookable(
        StoreSettings $settings,
        SlotKey $slotKey,
        ?string $holdToken,
        DateTimeImmutable $now,
        ?string $viewerSupplierId,
    ): void {
        $date = SlotGridService::localDate($slotKey->slotStart);
        $grid = $this->grid->build($settings, $date, $viewerSupplierId, $now);
        $slot = $this->grid->findSlot($grid, $slotKey);

        if (null === $slot) {
            throw SlotNotAvailableException::outsideGrid();
        }

        match ($slot->state) {
            SlotState::Available => null,
            // BOOK-05 / GRID-04: резерв чужого постачальника.
            SlotState::Reserved => throw new SlotReservedException(),
            SlotState::Booked => throw new SlotAlreadyBookedException($slotKey),
            SlotState::Blocked => throw new SlotNotAvailableException(SlotState::Blocked, 'Слот заблоковано магазином'),
            SlotState::Past => throw SlotNotAvailableException::leadTime($settings->config->leadTimeMinutes),
            // HOLD-03: підтвердити може лише власник холду; протухлий холд
            // не заважає — фінальну гарантію дає унікальний індекс (HOLD-04).
            SlotState::Held => $this->assertOwnHold($slotKey, $holdToken, $now),
        };
    }

    private function assertOwnHold(SlotKey $slotKey, ?string $holdToken, DateTimeImmutable $now): void
    {
        $hold = $this->holds->get($slotKey, $now);

        if (null === $hold) {
            return;
        }

        if (null === $holdToken || !$hold->isOwnedBy($holdToken)) {
            throw new SlotHeldException();
        }
    }

    /**
     * BOOK-09: активні майбутні бронювання (status=booked, slotStart > now)
     * одного постачальника обмежені maxActiveBookingsPerSupplier.
     */
    private function assertSupplierLimit(StoreSettings $settings, string $supplierId, DateTimeImmutable $now): void
    {
        $limit = $settings->policy->maxActiveBookingsPerSupplier;

        if ($this->bookings->countActiveFutureBySupplier($supplierId, $now) >= $limit) {
            throw new BookingLimitExceededException($limit);
        }
    }

    /**
     * BOOK-04 / EDGE-01: будь-який часовий перетин бронювань того самого
     * держномера в межах одного постачальника — попередження, не блокер.
     */
    private function assertNoVehicleConflict(
        string $supplierId,
        VehicleSnapshot $vehicle,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $excludeBookingId = null,
    ): void {
        $conflicts = $this->bookings->findOverlappingByPlate(
            $supplierId,
            $vehicle->plateNumber,
            $from,
            $to,
            $excludeBookingId,
        );

        if ([] === $conflicts) {
            return;
        }

        throw new VehicleTimeConflictException(
            $vehicle->plateNumber,
            array_map(static fn (Booking $booking) => [
                'bookingId' => $booking->id,
                'storeId' => $booking->storeId,
                'storeName' => $booking->storeSnapshot->displayName,
                'rampId' => $booking->rampId(),
                'slotStart' => $booking->slotStart->format('Y-m-d\TH:i:s\Z'),
                'slotEnd' => $booking->slotEnd->format('Y-m-d\TH:i:s\Z'),
            ], $conflicts),
        );
    }
}
