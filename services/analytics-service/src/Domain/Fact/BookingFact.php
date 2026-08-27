<?php

declare(strict_types=1);

namespace App\Domain\Fact;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;

/**
 * Денормалізований факт бронювання — базова read-модель analytics-service (KPI-05).
 *
 * Будується виключно з доменних подій RabbitMQ, а не прямими запитами в БД
 * booking-service (KPI-05). Це рядок read-моделі, тому клас навмисно мутабельний:
 * проєктор послідовно накладає на нього події. Уся ідентичність (bookingId,
 * storeId, city, supplierId, slotStart, slotEnd, type) — readonly.
 *
 * Ідемпотентність: кожен застосований eventId запамʼятовується у $processedEventIds,
 * повторна доставка тієї самої події не змінює факт (див. EventProjector).
 */
final class BookingFact
{
    /**
     * Місто, якого немає в довіднику філій.
     *
     * У мережі є філії з порожнім містом (наслідок синхронізації MCP —
     * див. docs/ui-issues.md, ISSUE-03), тож подія BookingCreated може чесно
     * не мати чим заповнити розріз. Відкидати через це ВЕСЬ факт не можна:
     * бронювання все одно рахується в KPI-02, KPI-03, KPI-04 і в лічильниках,
     * а місто — лише один із розрізів KPI-05. Тому замість втрати факту
     * зʼявляється явна, видима в інтерфейсі група.
     */
    public const string UNKNOWN_CITY = 'Місто не вказано';

    private BookingStatus $status;
    private ?\DateTimeImmutable $arrivedAt = null;
    private ?\DateTimeImmutable $unloadingStartedAt = null;
    private ?\DateTimeImmutable $completedAt = null;
    private ?\DateTimeImmutable $cancelledAt = null;
    private ?\DateTimeImmutable $noShowAt = null;
    private ?\DateTimeImmutable $rejectedAt = null;
    private ?int $unloadedPalletsCount = null;
    private bool $partialUnload = false;
    private bool $delayed = false;
    private ?string $delayReason = null;
    private ?\DateTimeImmutable $delayEta = null;
    private ?RejectionReason $rejectedReason = null;
    private string $rampId;
    private \DateTimeImmutable $updatedAt;

    /** @var array<string, true> мапа застосованих eventId → true */
    private array $processedEventIds = [];

    public function __construct(
        public readonly string $bookingId,
        public readonly string $storeId,
        public readonly string $city,
        public readonly string $supplierId,
        string $rampId,
        public readonly \DateTimeImmutable $slotStart,
        public readonly \DateTimeImmutable $slotEnd,
        public readonly BookingType $type,
        public readonly int $palletsCount,
        public readonly ?string $rescheduleOf = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        BookingStatus $status = BookingStatus::Booked,
    ) {
        $this->rampId = $rampId;
        $this->status = $status;
        $this->updatedAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Відновлення факту зі сховища (Mongo / InMemory-снапшот) без програвання подій.
     *
     * @param list<string> $processedEventIds
     */
    public static function restore(
        string $bookingId,
        string $storeId,
        string $city,
        string $supplierId,
        string $rampId,
        \DateTimeImmutable $slotStart,
        \DateTimeImmutable $slotEnd,
        BookingType $type,
        BookingStatus $status,
        int $palletsCount,
        ?\DateTimeImmutable $arrivedAt = null,
        ?\DateTimeImmutable $unloadingStartedAt = null,
        ?\DateTimeImmutable $completedAt = null,
        ?\DateTimeImmutable $cancelledAt = null,
        ?\DateTimeImmutable $noShowAt = null,
        ?\DateTimeImmutable $rejectedAt = null,
        ?int $unloadedPalletsCount = null,
        bool $partialUnload = false,
        bool $delayed = false,
        ?string $delayReason = null,
        ?\DateTimeImmutable $delayEta = null,
        ?RejectionReason $rejectedReason = null,
        ?string $rescheduleOf = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
        array $processedEventIds = [],
    ): self {
        $fact = new self(
            bookingId: $bookingId,
            storeId: $storeId,
            city: $city,
            supplierId: $supplierId,
            rampId: $rampId,
            slotStart: $slotStart,
            slotEnd: $slotEnd,
            type: $type,
            palletsCount: $palletsCount,
            rescheduleOf: $rescheduleOf,
            createdAt: $createdAt,
            status: $status,
        );

        $fact->arrivedAt = $arrivedAt;
        $fact->unloadingStartedAt = $unloadingStartedAt;
        $fact->completedAt = $completedAt;
        $fact->cancelledAt = $cancelledAt;
        $fact->noShowAt = $noShowAt;
        $fact->rejectedAt = $rejectedAt;
        $fact->unloadedPalletsCount = $unloadedPalletsCount;
        $fact->partialUnload = $partialUnload;
        $fact->delayed = $delayed;
        $fact->delayReason = $delayReason;
        $fact->delayEta = $delayEta;
        $fact->rejectedReason = $rejectedReason;
        $fact->updatedAt = $updatedAt ?? $fact->updatedAt;

        foreach ($processedEventIds as $eventId) {
            $fact->processedEventIds[$eventId] = true;
        }

        return $fact;
    }

    public function status(): BookingStatus
    {
        return $this->status;
    }

    public function rampId(): string
    {
        return $this->rampId;
    }

    public function arrivedAt(): ?\DateTimeImmutable
    {
        return $this->arrivedAt;
    }

    public function unloadingStartedAt(): ?\DateTimeImmutable
    {
        return $this->unloadingStartedAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function cancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function noShowAt(): ?\DateTimeImmutable
    {
        return $this->noShowAt;
    }

    public function rejectedAt(): ?\DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function unloadedPalletsCount(): ?int
    {
        return $this->unloadedPalletsCount;
    }

    public function isPartialUnload(): bool
    {
        return $this->partialUnload;
    }

    public function isDelayed(): bool
    {
        return $this->delayed;
    }

    public function delayReason(): ?string
    {
        return $this->delayReason;
    }

    public function delayEta(): ?\DateTimeImmutable
    {
        return $this->delayEta;
    }

    public function rejectedReason(): ?RejectionReason
    {
        return $this->rejectedReason;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<string> */
    public function processedEventIds(): array
    {
        return array_keys($this->processedEventIds);
    }

    public function hasProcessed(string $eventId): bool
    {
        return isset($this->processedEventIds[$eventId]);
    }

    public function markProcessed(string $eventId, \DateTimeImmutable $at): void
    {
        $this->processedEventIds[$eventId] = true;
        if ($at > $this->updatedAt) {
            $this->updatedAt = $at;
        }
    }

    /**
     * Просування статусу вперед. Захист від подій, доставлених не в порядку
     * публікації: статус ніколи не «відкочується» на нижчий ранг, а термінальний
     * статус не перезаписується іншим термінальним.
     */
    public function advanceStatusTo(BookingStatus $next): void
    {
        if ($next->rank() > $this->status->rank()) {
            $this->status = $next;
        }
    }

    public function applyArrived(\DateTimeImmutable $arrivedAt): void
    {
        $this->arrivedAt ??= $arrivedAt;
        $this->advanceStatusTo(BookingStatus::Arrived);
    }

    public function applyUnloadingStarted(\DateTimeImmutable $startedAt): void
    {
        $this->unloadingStartedAt ??= $startedAt;
        $this->advanceStatusTo(BookingStatus::Unloading);
    }

    public function applyUnloadingCompleted(
        \DateTimeImmutable $completedAt,
        ?int $unloadedPalletsCount,
        bool $partialUnload,
    ): void {
        $this->completedAt ??= $completedAt;
        $this->unloadedPalletsCount ??= $unloadedPalletsCount;
        $this->partialUnload = $partialUnload || $this->partialUnload;
        $this->advanceStatusTo(BookingStatus::Completed);
    }

    public function applyCancelled(\DateTimeImmutable $cancelledAt): void
    {
        $this->cancelledAt ??= $cancelledAt;
        $this->advanceStatusTo(BookingStatus::Cancelled);
    }

    public function applyNoShow(\DateTimeImmutable $noShowAt): void
    {
        $this->noShowAt ??= $noShowAt;
        $this->advanceStatusTo(BookingStatus::NoShow);
    }

    public function applyRejected(\DateTimeImmutable $rejectedAt, ?RejectionReason $reason): void
    {
        $this->rejectedAt ??= $rejectedAt;
        $this->rejectedReason ??= $reason;
        $this->advanceStatusTo(BookingStatus::Rejected);
    }

    /**
     * Затримка — атрибут поверх поточного статусу, а не окремий статус (глосарій 1.5).
     */
    public function applyDelay(bool $delayed, ?string $reason, ?\DateTimeImmutable $eta): void
    {
        $this->delayed = $delayed;
        $this->delayReason = $delayed ? $reason : null;
        $this->delayEta = $delayed ? $eta : null;
    }

    /** Переведення бронювання на іншу рампу (подія BookingReassigned). */
    public function applyReassignment(string $rampId): void
    {
        $this->rampId = $rampId;
    }

    /**
     * Тривалість очікування машини від arrived до unloading у хвилинах (KPI-03).
     * null, якщо хоча б однієї з подій ще немає або дані суперечливі.
     */
    public function waitingMinutes(): ?float
    {
        if ($this->arrivedAt === null || $this->unloadingStartedAt === null) {
            return null;
        }

        $seconds = $this->unloadingStartedAt->getTimestamp() - $this->arrivedAt->getTimestamp();

        return $seconds < 0 ? null : $seconds / 60.0;
    }

    /**
     * Тривалість розвантаження від unloading до completed у хвилинах (ANL-04).
     */
    public function unloadingMinutes(): ?float
    {
        if ($this->unloadingStartedAt === null || $this->completedAt === null) {
            return null;
        }

        $seconds = $this->completedAt->getTimestamp() - $this->unloadingStartedAt->getTimestamp();

        return $seconds < 0 ? null : $seconds / 60.0;
    }

    /**
     * Хвилини слота цього бронювання (використовується як booked_minutes у KPI-01,
     * коли розрахунок ведеться від бронювань, а не від інвентаря слотів).
     */
    public function slotMinutes(): float
    {
        return ($this->slotEnd->getTimestamp() - $this->slotStart->getTimestamp()) / 60.0;
    }
}
