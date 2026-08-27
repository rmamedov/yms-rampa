<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Exception\EditDeadlinePassedException;
use App\Domain\Booking\Exception\InvalidStatusTransitionException;
use App\Domain\Booking\Exception\PalletsOutOfRangeException;
use App\Domain\Booking\Exception\TransitionNotAllowedException;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Slot\SlotKey;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Агрегат бронювання (розділи 6.4–6.7, схема 10.3.1).
 *
 * Тут живе вся машина станів ST-01..ST-07 разом із правами на переходи;
 * кожен успішний перехід повертає канонічну доменну подію та дописує
 * запис у statusHistory (DATA-14 — журнал тільки append-only).
 */
final class Booking
{
    /**
     * Дозволені переходи машини станів (розділ 6.5). Усе, чого тут немає,
     * відхиляється як ST-06 → 409 INVALID_STATUS_TRANSITION.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        'booked' => ['arrived', 'cancelled', 'no_show'],
        'arrived' => ['unloading', 'rejected'],
        'unloading' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
        'rejected' => [],
    ];

    private BookingStatus $status;

    /** @var list<StatusChange> */
    private array $statusHistory;

    private DelayInfo $delayed;

    private ?DateTimeImmutable $arrivedAt = null;
    private ?DateTimeImmutable $unloadingStartedAt = null;
    private ?DateTimeImmutable $completedAt = null;
    private ?DateTimeImmutable $cancelledAt = null;
    private ?Cancellation $cancellation = null;
    private ?Rejection $rejection = null;
    private ?int $unloadedPalletsCount = null;
    private ?PartialUnload $partialUnload = null;
    private ?string $routeSheetId = null;

    /** EDIT-06 дозволяє магазину перевести бронювання на іншу рампу лише разово. */
    private bool $rampReassigned = false;

    private DateTimeImmutable $updatedAt;

    /**
     * @param list<StatusChange> $statusHistory
     */
    private function __construct(
        public readonly string $id,
        public readonly BookingType $type,
        public readonly string $storeId,
        public readonly StoreSnapshot $storeSnapshot,
        private string $rampId,
        public readonly DateTimeImmutable $slotStart,
        public readonly DateTimeImmutable $slotEnd,
        public readonly ?string $supplierId,
        public readonly string $supplierNameSnapshot,
        private VehicleSnapshot $vehicle,
        private ?string $driverId,
        private ?string $orderId,
        private int $palletsCount,
        BookingStatus $status,
        public readonly ?string $rescheduleOf,
        public readonly string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        array $statusHistory,
        ?DelayInfo $delayed = null,
    ) {
        self::assertPalletsInRange($palletsCount);

        if (BookingType::Scheduled === $type && null === $supplierId) {
            throw new ValidationFailedException('Планове бронювання неможливе без постачальника');
        }

        if ($slotEnd <= $slotStart) {
            throw new ValidationFailedException('Кінець слота має бути пізніше за початок');
        }

        $this->status = $status;
        $this->statusHistory = array_values($statusHistory);
        $this->delayed = $delayed ?? DelayInfo::none();
        $this->updatedAt = $createdAt;
    }

    /**
     * Планове бронювання постачальника (BOOK-06): створюється у статусі `booked`,
     * `type=scheduled`.
     */
    public static function schedule(
        string $id,
        string $storeId,
        StoreSnapshot $storeSnapshot,
        string $rampId,
        DateTimeImmutable $slotStart,
        DateTimeImmutable $slotEnd,
        string $supplierId,
        string $supplierNameSnapshot,
        VehicleSnapshot $vehicle,
        int $palletsCount,
        Actor $createdBy,
        DateTimeImmutable $now,
        ?string $driverId = null,
        ?string $orderId = null,
        ?string $rescheduleOf = null,
    ): self {
        $utc = new DateTimeZone('UTC');
        $now = $now->setTimezone($utc);

        return new self(
            id: $id,
            type: BookingType::Scheduled,
            storeId: $storeId,
            storeSnapshot: $storeSnapshot,
            rampId: $rampId,
            slotStart: $slotStart->setTimezone($utc),
            slotEnd: $slotEnd->setTimezone($utc),
            supplierId: $supplierId,
            supplierNameSnapshot: $supplierNameSnapshot,
            vehicle: $vehicle,
            driverId: $driverId,
            orderId: $orderId,
            palletsCount: $palletsCount,
            status: BookingStatus::Booked,
            rescheduleOf: $rescheduleOf,
            createdBy: $createdBy->userId,
            createdAt: $now,
            statusHistory: [StatusChange::madeBy(null, BookingStatus::Booked, $now, $createdBy)],
        );
    }

    /**
     * Позапланове прибуття (WALK-04): створюється магазином одразу у статусі
     * `arrived`, без стадії `booked`. `supplierId=null` допускається лише тут —
     * постачальник «поза системою» (DATA-31).
     */
    public static function walkIn(
        string $id,
        string $storeId,
        StoreSnapshot $storeSnapshot,
        string $rampId,
        DateTimeImmutable $slotStart,
        DateTimeImmutable $slotEnd,
        ?string $supplierId,
        string $supplierNameSnapshot,
        VehicleSnapshot $vehicle,
        int $palletsCount,
        Actor $createdBy,
        DateTimeImmutable $now,
        ?string $orderId = null,
    ): self {
        $utc = new DateTimeZone('UTC');
        $now = $now->setTimezone($utc);

        $booking = new self(
            id: $id,
            type: BookingType::WalkIn,
            storeId: $storeId,
            storeSnapshot: $storeSnapshot,
            rampId: $rampId,
            slotStart: $slotStart->setTimezone($utc),
            slotEnd: $slotEnd->setTimezone($utc),
            supplierId: $supplierId,
            supplierNameSnapshot: $supplierNameSnapshot,
            vehicle: $vehicle,
            driverId: null,
            orderId: $orderId,
            palletsCount: $palletsCount,
            status: BookingStatus::Arrived,
            rescheduleOf: null,
            createdBy: $createdBy->userId,
            createdAt: $now,
            statusHistory: [StatusChange::madeBy(null, BookingStatus::Arrived, $now, $createdBy)],
        );

        $booking->arrivedAt = $now;

        return $booking;
    }

    /**
     * Відновлення агрегату зі сховища. Використовується лише мапперами
     * інфраструктури — бізнес-логіка створює бронювання через schedule()/walkIn().
     *
     * @param list<StatusChange> $statusHistory
     */
    public static function reconstitute(
        string $id,
        BookingType $type,
        string $storeId,
        StoreSnapshot $storeSnapshot,
        string $rampId,
        DateTimeImmutable $slotStart,
        DateTimeImmutable $slotEnd,
        ?string $supplierId,
        string $supplierNameSnapshot,
        VehicleSnapshot $vehicle,
        ?string $driverId,
        ?string $orderId,
        int $palletsCount,
        BookingStatus $status,
        ?string $rescheduleOf,
        string $createdBy,
        DateTimeImmutable $createdAt,
        array $statusHistory,
        DelayInfo $delayed,
        ?DateTimeImmutable $arrivedAt = null,
        ?DateTimeImmutable $unloadingStartedAt = null,
        ?DateTimeImmutable $completedAt = null,
        ?DateTimeImmutable $cancelledAt = null,
        ?Cancellation $cancellation = null,
        ?Rejection $rejection = null,
        ?int $unloadedPalletsCount = null,
        ?PartialUnload $partialUnload = null,
        ?string $routeSheetId = null,
        bool $rampReassigned = false,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        $booking = new self(
            id: $id,
            type: $type,
            storeId: $storeId,
            storeSnapshot: $storeSnapshot,
            rampId: $rampId,
            slotStart: $slotStart,
            slotEnd: $slotEnd,
            supplierId: $supplierId,
            supplierNameSnapshot: $supplierNameSnapshot,
            vehicle: $vehicle,
            driverId: $driverId,
            orderId: $orderId,
            palletsCount: $palletsCount,
            status: $status,
            rescheduleOf: $rescheduleOf,
            createdBy: $createdBy,
            createdAt: $createdAt,
            statusHistory: $statusHistory,
            delayed: $delayed,
        );

        $booking->arrivedAt = $arrivedAt;
        $booking->unloadingStartedAt = $unloadingStartedAt;
        $booking->completedAt = $completedAt;
        $booking->cancelledAt = $cancelledAt;
        $booking->cancellation = $cancellation;
        $booking->rejection = $rejection;
        $booking->unloadedPalletsCount = $unloadedPalletsCount;
        $booking->partialUnload = $partialUnload;
        $booking->routeSheetId = $routeSheetId;
        $booking->rampReassigned = $rampReassigned;
        $booking->updatedAt = $updatedAt ?? $createdAt;

        return $booking;
    }

    // --- Читання стану -----------------------------------------------------

    public function status(): BookingStatus
    {
        return $this->status;
    }

    public function rampId(): string
    {
        return $this->rampId;
    }

    public function vehicle(): VehicleSnapshot
    {
        return $this->vehicle;
    }

    public function driverId(): ?string
    {
        return $this->driverId;
    }

    public function orderId(): ?string
    {
        return $this->orderId;
    }

    public function palletsCount(): int
    {
        return $this->palletsCount;
    }

    public function delayed(): DelayInfo
    {
        return $this->delayed;
    }

    public function arrivedAt(): ?DateTimeImmutable
    {
        return $this->arrivedAt;
    }

    public function unloadingStartedAt(): ?DateTimeImmutable
    {
        return $this->unloadingStartedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancellation(): ?Cancellation
    {
        return $this->cancellation;
    }

    public function rejection(): ?Rejection
    {
        return $this->rejection;
    }

    public function unloadedPalletsCount(): ?int
    {
        return $this->unloadedPalletsCount;
    }

    public function partialUnload(): ?PartialUnload
    {
        return $this->partialUnload;
    }

    public function routeSheetId(): ?string
    {
        return $this->routeSheetId;
    }

    public function rampReassigned(): bool
    {
        return $this->rampReassigned;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<StatusChange> */
    public function statusHistory(): array
    {
        return $this->statusHistory;
    }

    public function slotKey(): SlotKey
    {
        return new SlotKey($this->storeId, $this->rampId, $this->slotStart);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** Локальна дата слота в часовій зоні магазину — ключ маршрутного листа. */
    public function localDate(): string
    {
        return $this->slotStart->setTimezone(new DateTimeZone(\App\Domain\Slot\StoreConfig::TIMEZONE))->format('Y-m-d');
    }

    public function attachToRouteSheet(string $routeSheetId): void
    {
        $this->routeSheetId = $routeSheetId;
    }

    // --- Машина станів ST-01..ST-07 ---------------------------------------

    /**
     * ST-01: booked → arrived. Виконує водій («На місці» у driver-web) або
     * store_operator/store_manager у store-web.
     */
    public function markArrived(Actor $actor, DateTimeImmutable $now): DomainEvent
    {
        $this->transition($actor, BookingStatus::Arrived, $now);
        $this->arrivedAt = $this->utc($now);

        return $this->event(EventType::BookingArrived, $now, [
            'arrivedAt' => $this->arrivedAt->format('Y-m-d\TH:i:s\Z'),
            'delayed' => $this->delayed->flag,
        ]);
    }

    /**
     * ST-02: arrived → unloading. Виконує store_operator / store_manager.
     * Перехід знімає прапорець затримки (DLY-01).
     */
    public function startUnloading(Actor $actor, DateTimeImmutable $now): DomainEvent
    {
        $this->transition($actor, BookingStatus::Unloading, $now);
        $this->unloadingStartedAt = $this->utc($now);
        $this->delayed = DelayInfo::none();

        return $this->event(EventType::UnloadingStarted, $now, [
            'unloadingStartedAt' => $this->unloadingStartedAt->format('Y-m-d\TH:i:s\Z'),
            'rampId' => $this->rampId,
        ]);
    }

    /**
     * ST-03: unloading → completed. `unloadedPalletsCount` за замовчуванням
     * дорівнює заявленим `palletsCount`; якщо розвантажено не все — обовʼязковий
     * `partialUnload` з причиною з довідника.
     */
    public function complete(
        Actor $actor,
        DateTimeImmutable $now,
        ?int $unloadedPalletsCount = null,
        ?PartialUnload $partialUnload = null,
    ): DomainEvent {
        $unloaded = $unloadedPalletsCount ?? $this->palletsCount;

        if ($unloaded < 0 || $unloaded > $this->palletsCount) {
            throw new ValidationFailedException(\sprintf(
                'Розвантажено палет має бути в діапазоні 0..%d (заявлено %d)',
                $this->palletsCount,
                $this->palletsCount,
            ));
        }

        if ($unloaded < $this->palletsCount && null === $partialUnload) {
            throw new ValidationFailedException(
                'Часткове розвантаження потребує причини з довідника'
            );
        }

        if (null !== $partialUnload && $partialUnload->reason->requiresComment() && null === $partialUnload->comment) {
            throw new ValidationFailedException('Для причини «інше» потрібен коментар');
        }

        $this->transition($actor, BookingStatus::Completed, $now, [
            'unloadedPalletsCount' => $unloaded,
        ]);

        $this->completedAt = $this->utc($now);
        $this->unloadedPalletsCount = $unloaded;
        $this->partialUnload = $unloaded < $this->palletsCount ? $partialUnload : null;

        return $this->event(EventType::UnloadingCompleted, $now, [
            'completedAt' => $this->completedAt->format('Y-m-d\TH:i:s\Z'),
            'palletsCount' => $this->palletsCount,
            'unloadedPalletsCount' => $unloaded,
            'partialUnload' => $this->partialUnload?->toArray(),
        ]);
    }

    /**
     * ST-04: booked → cancelled. Постачальник — не пізніше дедлайну EDIT-02;
     * магазин і адмін — у будь-який момент до `arrived`, без дедлайну.
     */
    public function cancel(
        Actor $actor,
        DateTimeImmutable $now,
        int $editDeadlineHours,
        ?string $reason = null,
        ?string $rescheduledTo = null,
    ): DomainEvent {
        if ($actor->role->isSupplier()) {
            $this->assertEditDeadline($now, $editDeadlineHours);
        }

        $meta = null === $rescheduledTo ? [] : ['rescheduledTo' => $rescheduledTo];
        $this->transition($actor, BookingStatus::Cancelled, $now, $meta);

        $this->cancelledAt = $this->utc($now);
        $this->cancellation = new Cancellation(CancelledBy::fromActor($actor), $actor->userId, $reason);

        return $this->event(EventType::BookingCancelled, $now, [
            'cancelledAt' => $this->cancelledAt->format('Y-m-d\TH:i:s\Z'),
            'cancellation' => $this->cancellation->toArray(),
            'rescheduledTo' => $rescheduledTo,
        ]);
    }

    /**
     * ST-05 / NOSH-01 / NOSH-02: booked → no_show. Виконує cron (системний актор)
     * або store_operator/store_manager вручну після slotEnd — хто перший.
     */
    public function markNoShow(Actor $actor, DateTimeImmutable $now): DomainEvent
    {
        if (!$actor->system && $this->utc($now) < $this->slotEnd) {
            throw new ValidationFailedException(
                'Ручна позначка «не приїхав» можлива лише після завершення слоту'
            );
        }

        $this->transition($actor, BookingStatus::NoShow, $now, [
            'auto' => $actor->system,
        ]);

        return $this->event(EventType::BookingNoShow, $now, [
            'auto' => $actor->system,
            'slotEnd' => $this->slotEnd->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * ST-07: arrived → rejected. Магазин відмовляє в прийомі з обовʼязковою
     * причиною з довідника; для «інше» коментар обовʼязковий (DATA-32).
     */
    public function reject(
        Actor $actor,
        RejectionReason $reason,
        DateTimeImmutable $now,
        ?string $comment = null,
    ): DomainEvent {
        if ($reason->requiresComment() && (null === $comment || '' === trim($comment))) {
            throw new ValidationFailedException('Для причини «інше» коментар обовʼязковий');
        }

        $this->transition($actor, BookingStatus::Rejected, $now, ['reason' => $reason->value]);
        $this->rejection = new Rejection($now, $actor->userId, $reason, $comment);

        return $this->event(EventType::BookingRejected, $now, [
            'rejectedAt' => $this->rejection->toArray(),
        ]);
    }

    /** EDIT-03: скасоване бронювання миттєво повертає слот у пул. */
    public function slotReleasedEvent(DateTimeImmutable $now): DomainEvent
    {
        return $this->slotReleasedEventForRamp($this->rampId, $now);
    }

    /**
     * Звільнення конкретної рампи. Потрібне окремо для EDIT-06: після
     * переведення бронювання рампа вже змінена, а звільняється попередня.
     */
    public function slotReleasedEventForRamp(string $rampId, DateTimeImmutable $now): DomainEvent
    {
        $key = new SlotKey($this->storeId, $rampId, $this->slotStart);

        return new DomainEvent(
            type: EventType::SlotReleased,
            aggregateType: 'slot',
            aggregateId: $key->toString(),
            payload: [
                'storeId' => $this->storeId,
                'rampId' => $rampId,
                'slotStart' => $this->slotStart->format('Y-m-d\TH:i:s\Z'),
                'slotEnd' => $this->slotEnd->format('Y-m-d\TH:i:s\Z'),
                'releasedBookingId' => $this->id,
            ],
            occurredAt: $now,
        );
    }

    public function bookingCreatedEvent(DateTimeImmutable $now): DomainEvent
    {
        return $this->event(EventType::BookingCreated, $now, [
            'type' => $this->type->value,
            'rescheduleOf' => $this->rescheduleOf,
            'storeId' => $this->storeId,
            'rampId' => $this->rampId,
            'slotStart' => $this->slotStart->format('Y-m-d\TH:i:s\Z'),
            'slotEnd' => $this->slotEnd->format('Y-m-d\TH:i:s\Z'),
            'supplierId' => $this->supplierId,
            'supplierName' => $this->supplierNameSnapshot,
            'vehicle' => $this->vehicle->toArray(),
            'palletsCount' => $this->palletsCount,
            'driverId' => $this->driverId,
            'orderId' => $this->orderId,
            'status' => $this->status->value,
        ]);
    }

    // --- Зміни без переходу статусу ---------------------------------------

    /**
     * DLY-01: прапорець затримки ставлять водій або магазин на бронювання
     * у статусах booked/arrived. Статус НЕ змінюється.
     */
    public function setDelay(
        Actor $actor,
        DelayReason $reason,
        DateTimeImmutable $eta,
        DateTimeImmutable $now,
        ?string $comment = null,
    ): DomainEvent {
        if (BookingStatus::Booked !== $this->status && BookingStatus::Arrived !== $this->status) {
            throw new ValidationFailedException(
                'Затримку можна позначити лише для бронювання у статусі «booked» або «arrived»'
            );
        }

        if (!$actor->system
            && Role::Driver !== $actor->role
            && !$actor->role->isStoreStaff()
            && !$actor->role->isNetworkAdmin()
        ) {
            throw new ValidationFailedException('Позначити затримку може водій або магазин');
        }

        if ($this->utc($eta) <= $this->utc($now)) {
            throw new ValidationFailedException('ETA має бути в майбутньому');
        }

        if ($reason->requiresComment() && (null === $comment || '' === trim($comment))) {
            throw new ValidationFailedException('Для причини «інше» коментар обовʼязковий');
        }

        $reasonText = $reason->requiresComment() ? $reason->value.': '.trim((string) $comment) : $reason->value;
        $this->delayed = new DelayInfo(true, $reasonText, $eta);
        $this->touch($now);

        return $this->event(EventType::BookingDelaySet, $now, [
            'delayed' => $this->delayed->toArray(),
            'by' => $actor->userId,
        ]);
    }

    /** Зняти прапорець затримки може магазин або система. */
    public function clearDelay(Actor $actor, DateTimeImmutable $now): void
    {
        if (!$actor->system && !$actor->role->isStoreStaff() && !$actor->role->isNetworkAdmin()) {
            throw new ValidationFailedException('Зняти позначку затримки може лише магазин або система');
        }

        $this->delayed = DelayInfo::none();
        $this->touch($now);
    }

    /**
     * EDIT-05: зміна водія та/або авто БЕЗ зміни слота дозволена постачальнику
     * до статусу `arrived` незалежно від editDeadlineHours. При заміні авто
     * повторно виконується перевірка тоннажу BOOK-01.
     */
    public function reassign(
        Actor $actor,
        DateTimeImmutable $now,
        float $maxVehicleWeightTons,
        ?string $driverId = null,
        ?VehicleSnapshot $vehicle = null,
        bool $driverProvided = false,
    ): DomainEvent {
        if (BookingStatus::Booked !== $this->status) {
            throw new ValidationFailedException(
                'Змінювати водія та авто можна лише до прибуття на місце'
            );
        }

        if (null === $vehicle && !$driverProvided) {
            throw new ValidationFailedException('Не вказано ані водія, ані авто для заміни');
        }

        $previousDriverId = $this->driverId;

        if (null !== $vehicle) {
            $vehicle->assertFitsStoreLimit($maxVehicleWeightTons);
            $this->vehicle = $vehicle;
        }

        if ($driverProvided) {
            $this->driverId = $driverId;
        }

        $this->touch($now);

        return $this->event(EventType::BookingReassigned, $now, [
            'reason' => 'driver_or_vehicle',
            'previousDriverId' => $previousDriverId,
            'driverId' => $this->driverId,
            'vehicle' => $this->vehicle->toArray(),
            'by' => $actor->userId,
        ]);
    }

    /**
     * EDIT-06: магазин разово переводить бронювання у статусі booked/arrived
     * на іншу вільну рампу ТОГО САМОГО часового слота. Час і факт бронювання
     * не змінюються.
     */
    public function moveToRamp(Actor $actor, string $rampId, DateTimeImmutable $now): DomainEvent
    {
        if (!$actor->canOperateStore($this->storeId)) {
            throw new TransitionNotAllowedException($this->status, $this->status, $actor->role);
        }

        if (BookingStatus::Booked !== $this->status && BookingStatus::Arrived !== $this->status) {
            throw new ValidationFailedException(
                'Перевести на іншу рампу можна лише бронювання у статусі «booked» або «arrived»'
            );
        }

        if ($rampId === $this->rampId) {
            throw new ValidationFailedException('Бронювання вже закріплене за цією рампою');
        }

        if ($this->rampReassigned) {
            throw new ValidationFailedException('Переведення на іншу рампу вже виконувалося для цього бронювання');
        }

        $previousRampId = $this->rampId;
        $this->rampId = $rampId;
        $this->rampReassigned = true;
        $this->touch($now);

        return $this->event(EventType::BookingReassigned, $now, [
            'reason' => 'ramp',
            'previousRampId' => $previousRampId,
            'rampId' => $rampId,
            'by' => $actor->userId,
        ]);
    }

    /**
     * EDIT-04: редагування полів без зміни слота (orderId, palletsCount)
     * дозволене до статусу `arrived`.
     */
    public function updateDetails(
        Actor $actor,
        DateTimeImmutable $now,
        int $editDeadlineHours,
        ?string $orderId = null,
        ?int $palletsCount = null,
        bool $orderIdProvided = false,
    ): void {
        if (BookingStatus::Booked !== $this->status) {
            throw new ValidationFailedException('Редагувати бронювання можна лише до прибуття на місце');
        }

        if ($actor->role->isSupplier()) {
            $this->assertEditDeadline($now, $editDeadlineHours);
        }

        if (null !== $palletsCount) {
            self::assertPalletsInRange($palletsCount);
            $this->palletsCount = $palletsCount;
        }

        if ($orderIdProvided) {
            $this->orderId = $orderId;
        }

        $this->touch($now);
    }

    /** Водій може дописати orderId пізніше (розділ 6.4). */
    public function setOrderId(?string $orderId, DateTimeImmutable $now): void
    {
        $this->orderId = $orderId;
        $this->touch($now);
    }

    /**
     * DRV: бронювання має входити до маршрутного листа саме цього водія.
     *
     * Єдина точка перевірки належності для всіх дій контуру водія —
     * відмітки «На місці», повідомлення про затримку і дописування orderId.
     */
    public function assertDriverOwnsPoint(Actor $actor): void
    {
        if (!$actor->canActOnOwnRouteSheet($this->driverId)) {
            throw AccessDeniedException::foreignRouteSheet();
        }
    }

    /**
     * DRV: водій дописує номер замовлення, якщо його не вказав постачальник
     * (розділ 6.4). Це ЄДИНЕ поле бронювання, яке водій змінює: ані палети,
     * ані авто, ані слот через цей шлях недосяжні.
     *
     * Дозволено до початку розвантаження — після нього номер уже потрібен
     * магазину для приймання.
     */
    public function setOrderIdByDriver(Actor $actor, ?string $orderId, DateTimeImmutable $now): void
    {
        $this->assertDriverOwnsPoint($actor);

        if (BookingStatus::Booked !== $this->status && BookingStatus::Arrived !== $this->status) {
            throw new ValidationFailedException(
                'Номер замовлення можна вказати лише до початку розвантаження'
            );
        }

        $this->setOrderId($orderId, $now);
    }

    public function assignDriver(?string $driverId, DateTimeImmutable $now): void
    {
        $this->driverId = $driverId;
        $this->touch($now);
    }

    // --- Правила -----------------------------------------------------------

    /**
     * EDIT-02: постачальник може змінювати або скасовувати бронювання
     * не пізніше ніж за editDeadlineHours до slotStart.
     */
    public function assertEditDeadline(DateTimeImmutable $now, int $editDeadlineHours): void
    {
        $deadline = $this->slotStart->modify(\sprintf('-%d hours', $editDeadlineHours));

        if ($this->utc($now) > $deadline) {
            throw new EditDeadlinePassedException($editDeadlineHours);
        }
    }

    public static function assertPalletsInRange(int $palletsCount): void
    {
        if ($palletsCount < PalletsOutOfRangeException::MIN || $palletsCount > PalletsOutOfRangeException::MAX) {
            throw new PalletsOutOfRangeException($palletsCount);
        }
    }

    /**
     * Виконати перехід: спершу перевірка машини станів (ST-06), потім прав
     * ініціатора, і лише тоді — запис у журнал (DATA-14).
     *
     * @param array<string, mixed> $meta
     */
    private function transition(Actor $actor, BookingStatus $to, DateTimeImmutable $now, array $meta = []): void
    {
        if (!\in_array($to->value, self::ALLOWED_TRANSITIONS[$this->status->value], true)) {
            throw new InvalidStatusTransitionException($this->status, $to);
        }

        $this->assertActorMayTransition($actor, $to);

        $from = $this->status;
        $this->status = $to;
        $this->statusHistory[] = StatusChange::madeBy($from, $to, $now, $actor, $meta);
        $this->touch($now);
    }

    /**
     * Колонка «Хто виконує» таблиці ST-01..ST-07. Адміністратори мережі
     * (super_admin, network_manager) мають повноваження магазину в будь-якій
     * філії, тому дозволені скрізь, де дозволений магазин.
     */
    private function assertActorMayTransition(Actor $actor, BookingStatus $to): void
    {
        if ($actor->system) {
            // Системний актор виконує лише авто-no_show (NOSH-01).
            if (BookingStatus::NoShow === $to) {
                return;
            }

            throw new TransitionNotAllowedException($this->status, $to, $actor->role);
        }

        $store = $actor->canOperateStore($this->storeId);
        $supplier = $actor->role->isSupplier() && $actor->belongsToSupplier((string) $this->supplierId);
        // Належність точки водієві визначає ПРОФІЛЬ (X-Driver-Profile-Id),
        // а не обліковий запис із `sub` — єдина реалізація в Actor.
        $driver = $actor->canActOnOwnRouteSheet($this->driverId);

        $allowed = match ($to) {
            // ST-01: водій цього бронювання або співробітник магазину.
            BookingStatus::Arrived => $driver || $store,
            // ST-02, ST-03, ST-07: тільки магазин.
            BookingStatus::Unloading, BookingStatus::Completed, BookingStatus::Rejected => $store,
            // ST-04: постачальник-власник або магазин/адмін.
            BookingStatus::Cancelled => $supplier || $store,
            // ST-05: магазин вручну (NOSH-02) або cron (гілка вище).
            BookingStatus::NoShow => $store,
            default => false,
        };

        if (!$allowed) {
            throw new TransitionNotAllowedException($this->status, $to, $actor->role);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(EventType $type, DateTimeImmutable $now, array $payload): DomainEvent
    {
        return DomainEvent::forBooking($type, $this->id, array_merge([
            'bookingId' => $this->id,
            'type' => $this->type->value,
            'storeId' => $this->storeId,
            'supplierId' => $this->supplierId,
            'status' => $this->status->value,
        ], $payload), $now);
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $this->utc($now);
    }

    private function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
