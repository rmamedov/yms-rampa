<?php

declare(strict_types=1);

namespace App\Domain\RouteSheet;

use App\Domain\Exception\ValidationFailedException;

/**
 * Маршрутний лист — агрегат бронювань постачальника на дату (RSHT-01).
 *
 * Створюється автоматично при першому бронюванні пари (постачальник, дата);
 * кожне наступне бронювання цієї дати додається, скасовані — виключаються.
 * Порядок точок — за часом слоту (RSHT-03).
 */
final class RouteSheet
{
    /** @var list<RouteSheetEntry> */
    private array $entries;

    private int $printVersion;

    /**
     * @param list<RouteSheetEntry> $entries
     */
    private function __construct(
        public readonly string $id,
        public readonly string $supplierId,
        /** Локальна дата Києва, "YYYY-MM-DD". */
        public readonly string $date,
        array $entries,
        int $printVersion,
    ) {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationFailedException('Дата маршрутного листа має бути у форматі Y-m-d');
        }

        $this->entries = array_values($entries);
        $this->printVersion = $printVersion;
    }

    public static function open(string $id, string $supplierId, string $date): self
    {
        return new self($id, $supplierId, $date, [], 1);
    }

    /**
     * @param list<RouteSheetEntry> $entries
     */
    public static function reconstitute(
        string $id,
        string $supplierId,
        string $date,
        array $entries,
        int $printVersion,
    ): self {
        return new self($id, $supplierId, $date, $entries, $printVersion);
    }

    /** @return list<RouteSheetEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function printVersion(): int
    {
        return $this->printVersion;
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function hasBooking(string $bookingId): bool
    {
        return null !== $this->entryFor($bookingId);
    }

    public function entryFor(string $bookingId): ?RouteSheetEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->bookingId === $bookingId) {
                return $entry;
            }
        }

        return null;
    }

    public function driverFor(string $bookingId): ?string
    {
        return $this->entryFor($bookingId)?->driverId;
    }

    /**
     * Привести склад листа до фактичного набору активних бронювань дати,
     * зберігши призначення водіїв (RSHT-01, DATA-15: зміна складу інкрементує
     * printVersion).
     *
     * @param list<string> $orderedBookingIds ідентифікатори в порядку часу слоту
     */
    public function syncWith(array $orderedBookingIds): void
    {
        $before = array_map(static fn (RouteSheetEntry $entry) => $entry->bookingId, $this->entries);

        $drivers = [];
        foreach ($this->entries as $entry) {
            $drivers[$entry->bookingId] = $entry->driverId;
        }

        $entries = [];
        $order = 1;
        foreach ($orderedBookingIds as $bookingId) {
            $entries[] = new RouteSheetEntry($bookingId, $drivers[$bookingId] ?? null, $order++);
        }

        $this->entries = $entries;

        if ($before !== $orderedBookingIds) {
            ++$this->printVersion;
        }
    }

    /**
     * RSHT-02: призначення водія на весь лист.
     *
     * `null` — це ЗНЯТТЯ водія з усього листа, а не «нічого не робити»:
     * симетрично до assignDriverToBooking(). Кабінет постачальника пропонує
     * варіант «Водія не призначено» у тому самому списку, і він мусить
     * виконувати дію, а не показувати стан, якого не сталося (ISSUE-18).
     */
    public function assignDriverToSheet(?string $driverId): void
    {
        $this->entries = array_map(
            static fn (RouteSheetEntry $entry) => $entry->withDriver($driverId),
            $this->entries,
        );

        ++$this->printVersion;
    }

    /** RSHT-02: призначення водія на окреме бронювання перекриває призначення листа. */
    public function assignDriverToBooking(string $bookingId, ?string $driverId): void
    {
        if (!$this->hasBooking($bookingId)) {
            throw new ValidationFailedException('Бронювання не входить до цього маршрутного листа');
        }

        $this->entries = array_map(
            static fn (RouteSheetEntry $entry) => $entry->bookingId === $bookingId
                ? $entry->withDriver($driverId)
                : $entry,
            $this->entries,
        );

        ++$this->printVersion;
    }

    /**
     * RSHT-04: бронювання, які водій бачить у driver-web.
     *
     * @return list<string>
     */
    public function bookingIdsForDriver(string $driverId): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry->driverId === $driverId) {
                $result[] = $entry->bookingId;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            '_id' => $this->id,
            'supplierId' => $this->supplierId,
            'date' => $this->date,
            'entries' => array_map(static fn (RouteSheetEntry $entry) => $entry->toArray(), $this->entries),
            'printVersion' => $this->printVersion,
        ];
    }
}
