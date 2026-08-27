<?php

declare(strict_types=1);

namespace App\Application\RouteSheet;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\Exception\BookingNotFoundException;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\RouteSheet\RouteSheet;
use App\Domain\RouteSheet\RouteSheetRepository;
use App\Domain\Shared\IdGenerator;
use App\Domain\Slot\StoreConfig;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Маршрутні листи (RSHT-01..RSHT-04).
 *
 * Лист створюється автоматично при першому бронюванні пари
 * (постачальник, дата); склад листа завжди перебудовується з фактичних
 * активних бронювань цієї дати, тому скасовані точки зникають самі (DATA-15).
 */
final readonly class RouteSheetService
{
    public function __construct(
        private RouteSheetRepository $sheets,
        private BookingRepository $bookings,
        private IdGenerator $ids,
    ) {
    }

    /**
     * RSHT-01: гарантує існування листа на пару (постачальник, дата)
     * і повертає його id — щоб покласти зворотне посилання в бронювання
     * ще до вставки.
     */
    public function ensureSheet(string $supplierId, string $date): RouteSheet
    {
        $sheet = $this->sheets->findBySupplierAndDate($supplierId, $date);

        if (null === $sheet) {
            $sheet = RouteSheet::open($this->ids->generate(), $supplierId, $date);
            $this->sheets->save($sheet);
        }

        return $sheet;
    }

    /**
     * Перебудувати склад листа з активних бронювань дати, впорядкованих
     * за часом слоту (RSHT-03).
     */
    public function sync(string $supplierId, string $date): RouteSheet
    {
        $sheet = $this->ensureSheet($supplierId, $date);

        $bookings = array_values(array_filter(
            $this->bookings->findBySupplierAndLocalDate($supplierId, $date),
            static fn (Booking $booking) => $booking->isActive() && BookingType::Scheduled === $booking->type,
        ));

        usort($bookings, static fn (Booking $a, Booking $b) => [$a->slotStart, $a->rampId()] <=> [$b->slotStart, $b->rampId()]);

        $sheet->syncWith(array_map(static fn (Booking $booking) => $booking->id, $bookings));
        $this->sheets->save($sheet);

        return $sheet;
    }

    /** Синхронізація листа для конкретного бронювання (після створення/скасування). */
    public function syncForBooking(Booking $booking): ?RouteSheet
    {
        if (BookingType::Scheduled !== $booking->type || null === $booking->supplierId) {
            return null;
        }

        return $this->sync($booking->supplierId, $booking->localDate());
    }

    /** RSHT-02: призначення водія на весь лист. */
    public function assignDriverToSheet(Actor $actor, string $supplierId, string $date, string $driverId, DateTimeImmutable $now): RouteSheet
    {
        $this->assertSupplierManager($actor, $supplierId);

        $sheet = $this->sync($supplierId, $date);
        $sheet->assignDriverToSheet($driverId);
        $this->sheets->save($sheet);

        foreach ($sheet->entries() as $entry) {
            $booking = $this->bookings->find($entry->bookingId);

            if (null === $booking || $booking->driverId() === $driverId) {
                continue;
            }

            $this->applyDriver($booking, $driverId, $actor, $now);
        }

        return $sheet;
    }

    /**
     * RSHT-02: призначення водія на окреме бронювання перекриває призначення
     * листа. Зміна водія бронювання негайно оновлює листи обох водіїв (EDIT-05).
     */
    public function assignDriverToBooking(Actor $actor, string $bookingId, ?string $driverId, DateTimeImmutable $now): RouteSheet
    {
        $booking = $this->bookings->find($bookingId);

        if (null === $booking || null === $booking->supplierId) {
            throw new BookingNotFoundException($bookingId);
        }

        $this->assertSupplierManager($actor, $booking->supplierId);

        $sheet = $this->sync($booking->supplierId, $booking->localDate());
        $sheet->assignDriverToBooking($bookingId, $driverId);
        $this->sheets->save($sheet);

        if ($booking->driverId() !== $driverId) {
            $this->applyDriver($booking, $driverId, $actor, $now);
        }

        return $sheet;
    }

    /**
     * RSHT-04: водій бачить лише власні маршрутні листи.
     *
     * @return list<array<string, mixed>>
     */
    public function forDriver(string $driverId, string $date): array
    {
        $result = [];

        foreach ($this->sheets->findByDriverAndDate($driverId, $date) as $sheet) {
            $bookings = [];

            foreach ($sheet->bookingIdsForDriver($driverId) as $bookingId) {
                $booking = $this->bookings->find($bookingId);

                if (null !== $booking && $booking->isActive()) {
                    $bookings[] = self::point($booking, $driverId);
                }
            }

            if ([] === $bookings) {
                continue;
            }

            $result[] = [
                'routeSheetId' => $sheet->id,
                'supplierId' => $sheet->supplierId,
                'date' => $sheet->date,
                'printVersion' => $sheet->printVersion(),
                'points' => $bookings,
            ];
        }

        return $result;
    }

    /**
     * RSHT-03: дані друкованої версії листа. Імʼя і телефон водія збагачує
     * supplier-web з partner-service — booking-service зберігає лише driverId.
     *
     * @return array<string, mixed>
     */
    public function printView(Actor $actor, string $supplierId, string $date): array
    {
        $this->assertSupplierManager($actor, $supplierId);

        $sheet = $this->sync($supplierId, $date);
        $points = [];
        $supplierName = null;

        foreach ($sheet->entries() as $entry) {
            $booking = $this->bookings->find($entry->bookingId);

            if (null === $booking) {
                continue;
            }

            $supplierName ??= $booking->supplierNameSnapshot;
            $points[] = self::point($booking, $entry->driverId);
        }

        return [
            'routeSheetId' => $sheet->id,
            'supplierId' => $supplierId,
            'supplierName' => $supplierName,
            'date' => $date,
            'printVersion' => $sheet->printVersion(),
            'points' => $points,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function point(Booking $booking, ?string $driverId): array
    {
        $tz = new DateTimeZone(StoreConfig::TIMEZONE);

        return [
            'bookingId' => $booking->id,
            'city' => $booking->storeSnapshot->city,
            'storeName' => $booking->storeSnapshot->displayName,
            'address' => $booking->storeSnapshot->address,
            'localTime' => $booking->slotStart->setTimezone($tz)->format('H:i'),
            'slotStart' => $booking->slotStart->format('Y-m-d\TH:i:s\Z'),
            'rampId' => $booking->rampId(),
            'orderId' => $booking->orderId(),
            'palletsCount' => $booking->palletsCount(),
            'plateNumber' => $booking->vehicle()->plateNumber,
            'driverId' => $driverId,
            'status' => $booking->status()->value,
        ];
    }

    private function applyDriver(Booking $booking, ?string $driverId, Actor $actor, DateTimeImmutable $now): void
    {
        $previousDriverId = $booking->driverId();
        $booking->assignDriver($driverId, $now);

        $this->bookings->save($booking, [
            DomainEvent::forBooking(EventType::BookingReassigned, $booking->id, [
                'bookingId' => $booking->id,
                'reason' => 'driver_assignment',
                'previousDriverId' => $previousDriverId,
                'driverId' => $driverId,
                'by' => $actor->userId,
            ], $now),
        ]);
    }

    private function assertSupplierManager(Actor $actor, string $supplierId): void
    {
        if ($actor->role->isNetworkAdmin()) {
            return;
        }

        if (Role::SupplierAdmin !== $actor->role && Role::SupplierOperator !== $actor->role) {
            throw new AccessDeniedException('Керувати маршрутними листами може лише кабінет постачальника');
        }

        if (!$actor->belongsToSupplier($supplierId)) {
            throw AccessDeniedException::foreignBooking();
        }
    }
}
