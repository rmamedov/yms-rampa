<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Outbox\OutboxStore;
use App\Domain\Slot\SlotKey;
use DateTimeImmutable;

/**
 * Реалізація сховища бронювань у памʼяті.
 *
 * Емулює ту саму гарантію, що й частковий унікальний індекс MongoDB
 * (DATA-12, BOOK-07): на ключ (storeId, rampId, slotStart) одночасно може
 * існувати рівно одне бронювання в активному статусі.
 *
 * Агрегати зберігаються і повертаються копіями — інакше зміни «протікали б»
 * у сховище до виклику save() і тест атомарності EDIT-01 був би несправжнім.
 */
final class InMemoryBookingRepository implements BookingRepository
{
    /** @var array<string, Booking> */
    private array $bookings = [];

    public function __construct(private readonly OutboxStore $outbox)
    {
    }

    public function insertIfSlotFree(Booking $booking, array $events): void
    {
        $this->assertSlotFree($booking);

        $this->bookings[$booking->id] = clone $booking;
        $this->outbox->append($events);
    }

    public function save(Booking $booking, array $events): void
    {
        // Зміна рампи (EDIT-06) переносить документ на інший ключ слота —
        // унікальність перевіряється так само, як це зробив би індекс.
        $this->assertSlotFree($booking);

        $this->bookings[$booking->id] = clone $booking;
        $this->outbox->append($events);
    }

    public function reschedule(Booking $newBooking, Booking $cancelledBooking, array $events): void
    {
        // EDIT-01: спершу вставка нового бронювання. Якщо слот зайнято —
        // виняток, і старе бронювання лишається в сховищі недоторканим.
        $this->assertSlotFree($newBooking);

        $this->bookings[$newBooking->id] = clone $newBooking;
        $this->bookings[$cancelledBooking->id] = clone $cancelledBooking;
        $this->outbox->append($events);
    }

    public function find(string $bookingId): ?Booking
    {
        $booking = $this->bookings[$bookingId] ?? null;

        return null === $booking ? null : clone $booking;
    }

    public function findActiveBySlotKey(SlotKey $slotKey): ?Booking
    {
        foreach ($this->bookings as $booking) {
            if ($booking->isActive() && $booking->slotKey()->equals($slotKey)) {
                return clone $booking;
            }
        }

        return null;
    }

    public function activeSlotKeys(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $keys = [];

        foreach ($this->bookings as $booking) {
            if (!$booking->isActive() || $booking->storeId !== $storeId) {
                continue;
            }

            if ($booking->slotStart >= $from && $booking->slotStart < $to) {
                $keys[] = $booking->slotKey()->toString();
            }
        }

        return array_values(array_unique($keys));
    }

    public function countActiveFutureBySupplier(string $supplierId, DateTimeImmutable $now): int
    {
        $count = 0;

        foreach ($this->bookings as $booking) {
            if ($booking->supplierId !== $supplierId) {
                continue;
            }

            // BOOK-09: рахуються лише майбутні `booked`; walk-in у ліміт не входять.
            if (BookingType::WalkIn === $booking->type) {
                continue;
            }

            if (BookingStatus::Booked === $booking->status() && $booking->slotStart > $now) {
                ++$count;
            }
        }

        return $count;
    }

    public function findOverlappingByPlate(
        string $supplierId,
        string $plateNumber,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $excludeBookingId = null,
    ): array {
        $result = [];

        foreach ($this->bookings as $booking) {
            if ($booking->id === $excludeBookingId || $booking->supplierId !== $supplierId) {
                continue;
            }

            if (!$booking->isActive() || $booking->vehicle()->plateNumber !== $plateNumber) {
                continue;
            }

            // BOOK-04: будь-який часовий перетин, незалежно від магазину.
            if ($booking->slotStart < $to && $booking->slotEnd > $from) {
                $result[] = clone $booking;
            }
        }

        return $result;
    }

    public function findNoShowCandidates(DateTimeImmutable $slotEndBefore): array
    {
        $result = [];

        foreach ($this->bookings as $booking) {
            if (BookingStatus::Booked === $booking->status() && $booking->slotEnd <= $slotEndBefore) {
                $result[] = clone $booking;
            }
        }

        usort($result, static fn (Booking $a, Booking $b) => $a->slotEnd <=> $b->slotEnd);

        return $result;
    }

    public function findBySupplierAndLocalDate(string $supplierId, string $localDate): array
    {
        $result = [];

        foreach ($this->bookings as $booking) {
            if ($booking->supplierId === $supplierId && $booking->localDate() === $localDate) {
                $result[] = clone $booking;
            }
        }

        return $result;
    }

    public function findByStoreAndRange(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $result = [];

        foreach ($this->bookings as $booking) {
            if ($booking->storeId === $storeId && $booking->slotStart >= $from && $booking->slotStart < $to) {
                $result[] = clone $booking;
            }
        }

        usort($result, static fn (Booking $a, Booking $b) => $a->slotStart <=> $b->slotStart);

        return $result;
    }

    /** @return list<Booking> */
    public function all(): array
    {
        return array_values(array_map(static fn (Booking $booking) => clone $booking, $this->bookings));
    }

    public function clear(): void
    {
        $this->bookings = [];
    }

    /**
     * Емуляція часткового унікального індексу
     * {storeId, rampId, slotStart} where status ∈ {booked, arrived, unloading}.
     */
    private function assertSlotFree(Booking $booking): void
    {
        if (!$booking->isActive()) {
            return;
        }

        $key = $booking->slotKey();

        foreach ($this->bookings as $existing) {
            if ($existing->id === $booking->id || !$existing->isActive()) {
                continue;
            }

            if ($existing->slotKey()->equals($key)) {
                throw new SlotAlreadyBookedException($key);
            }
        }
    }
}
