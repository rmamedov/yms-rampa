<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Fact\BookingFact;
use App\Domain\Slot\SlotFact;

/**
 * Фільтри дашбордів аналітики (ANL-10): період з/по, місто, магазини
 * (мультивибір), постачальники (мультивибір), тип бронювання, статуси.
 *
 * Період — напівінтервал [from; to) в UTC: бронювання відноситься до періоду
 * за slotStart, слот — за start.
 */
final readonly class AnalyticsQuery
{
    /**
     * @param list<string>         $cities
     * @param list<string>         $storeIds
     * @param list<string>         $supplierIds
     * @param list<string>         $rampIds
     * @param list<BookingType>    $types
     * @param list<BookingStatus>  $statuses
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $cities = [],
        public array $storeIds = [],
        public array $supplierIds = [],
        public array $rampIds = [],
        public array $types = [],
        public array $statuses = [],
    ) {
    }

    public function withPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self(
            from: $from,
            to: $to,
            cities: $this->cities,
            storeIds: $this->storeIds,
            supplierIds: $this->supplierIds,
            rampIds: $this->rampIds,
            types: $this->types,
            statuses: $this->statuses,
        );
    }

    public function matchesFact(BookingFact $fact): bool
    {
        if ($fact->slotStart < $this->from || $fact->slotStart >= $this->to) {
            return false;
        }

        if ($this->cities !== [] && !in_array($fact->city, $this->cities, true)) {
            return false;
        }

        if ($this->storeIds !== [] && !in_array($fact->storeId, $this->storeIds, true)) {
            return false;
        }

        if ($this->supplierIds !== [] && !in_array($fact->supplierId, $this->supplierIds, true)) {
            return false;
        }

        if ($this->rampIds !== [] && !in_array($fact->rampId(), $this->rampIds, true)) {
            return false;
        }

        if ($this->types !== [] && !in_array($fact->type, $this->types, true)) {
            return false;
        }

        if ($this->statuses !== [] && !in_array($fact->status(), $this->statuses, true)) {
            return false;
        }

        return true;
    }

    /**
     * Для інвентаря слотів застосовуються лише розрізи, що мають сенс для слота:
     * період, місто, магазин, рампа. Фільтри постачальника, типу і статусу
     * бронювання до слотів не застосовуються.
     */
    public function matchesSlot(SlotFact $slot): bool
    {
        if ($slot->start < $this->from || $slot->start >= $this->to) {
            return false;
        }

        if ($this->cities !== [] && !in_array($slot->city, $this->cities, true)) {
            return false;
        }

        if ($this->storeIds !== [] && !in_array($slot->storeId, $this->storeIds, true)) {
            return false;
        }

        if ($this->rampIds !== [] && !in_array($slot->rampId, $this->rampIds, true)) {
            return false;
        }

        return true;
    }

    /** Опис застосованих фільтрів для рядка-заголовка CSV-експорту (ANL-11). */
    public function describe(): string
    {
        $parts = [sprintf(
            'період: %s — %s (UTC)',
            $this->from->format('Y-m-d H:i'),
            $this->to->format('Y-m-d H:i'),
        )];

        if ($this->cities !== []) {
            $parts[] = 'міста: ' . implode('|', $this->cities);
        }
        if ($this->storeIds !== []) {
            $parts[] = 'магазини: ' . implode('|', $this->storeIds);
        }
        if ($this->supplierIds !== []) {
            $parts[] = 'постачальники: ' . implode('|', $this->supplierIds);
        }
        if ($this->rampIds !== []) {
            $parts[] = 'рампи: ' . implode('|', $this->rampIds);
        }
        if ($this->types !== []) {
            $parts[] = 'тип: ' . implode('|', array_map(static fn (BookingType $t): string => $t->value, $this->types));
        }
        if ($this->statuses !== []) {
            $parts[] = 'статуси: ' . implode('|', array_map(static fn (BookingStatus $s): string => $s->value, $this->statuses));
        }

        return implode('; ', $parts);
    }
}
