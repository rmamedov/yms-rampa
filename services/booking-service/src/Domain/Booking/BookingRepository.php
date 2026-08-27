<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Event\DomainEvent;
use App\Domain\Slot\SlotKey;
use DateTimeImmutable;

/**
 * Сховище бронювань. Домен знає лише цей контракт — реалізації на MongoDB
 * та в памʼяті лежать в Infrastructure.
 *
 * Кожен метод запису приймає список доменних подій і зобовʼязаний записати
 * бізнес-документ і outbox в ОДНІЙ операції (DATA-16, transactional outbox).
 */
interface BookingRepository
{
    /**
     * BOOK-07/BOOK-08: атомарна вставка бронювання лише за умови, що на
     * ключ слота (storeId, rampId, slotStart) немає активного бронювання
     * (status ∈ booked|arrived|unloading).
     *
     * @param list<DomainEvent> $events
     *
     * @throws SlotAlreadyBookedException якщо активне бронювання вже існує
     */
    public function insertIfSlotFree(Booking $booking, array $events): void;

    /**
     * Збереження змін наявного бронювання разом із подіями переходу.
     *
     * @param list<DomainEvent> $events
     */
    public function save(Booking $booking, array $events): void;

    /**
     * EDIT-01: перенесення слота — атомарна пара «вставити нове + скасувати старе».
     * Якщо новий слот зайнятий, старе бронювання лишається недоторканим,
     * часткові стани заборонені.
     *
     * @param list<DomainEvent> $events
     *
     * @throws SlotAlreadyBookedException якщо новий слот уже зайнято
     */
    public function reschedule(Booking $newBooking, Booking $cancelledBooking, array $events): void;

    public function find(string $bookingId): ?Booking;

    /** Активне бронювання на ключі слота, якщо є. */
    public function findActiveBySlotKey(SlotKey $slotKey): ?Booking;

    /**
     * Ключі слотів з активними бронюваннями магазину в діапазоні —
     * накладання `booked` на сітку (крок 7 GRID-01).
     *
     * @return list<string> рядкові ключі SlotKey::toString()
     */
    public function activeSlotKeys(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * BOOK-09: кількість активних майбутніх бронювань постачальника
     * (status=booked, slotStart > now). Walk-in у ліміт не входять.
     */
    public function countActiveFutureBySupplier(string $supplierId, DateTimeImmutable $now): int;

    /**
     * SUP-06: чи існує хоч одне бронювання постачальника БУДЬ-ЯКОГО статусу
     * і будь-якого типу — включно зі скасованими, no_show і walk-in.
     *
     * Питання ставить partner-service перед видаленням постачальника
     * (службовий маршрут GET /internal/v1/bookings/suppliers/{supplierId}).
     * Саме «будь-який статус», а не «активні»: історія поставок не повинна
     * лишитися з посиланням на неіснуючого контрагента.
     */
    public function hasAnyBySupplier(string $supplierId): bool;

    /**
     * BOOK-04: активні бронювання того самого постачальника з тим самим
     * держномером, що перетинаються за часом з [from, to) — незалежно від магазину.
     *
     * @return list<Booking>
     */
    public function findOverlappingByPlate(
        string $supplierId,
        string $plateNumber,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $excludeBookingId = null,
    ): array;

    /**
     * NOSH-01: кандидати на авто-no_show — усе, що досі в статусі `booked`
     * і чий slotEnd уже позаду.
     *
     * @return list<Booking>
     */
    public function findNoShowCandidates(DateTimeImmutable $slotEndBefore): array;

    /**
     * @return list<Booking>
     */
    public function findBySupplierAndLocalDate(string $supplierId, string $localDate): array;

    /**
     * @return list<Booking>
     */
    public function findByStoreAndRange(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
