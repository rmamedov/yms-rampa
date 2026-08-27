<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Fact\BookingFact;
use App\Domain\Slot\SlotFact;

/**
 * Розрізи аналітики (KPI-05): мережа / місто / магазин / постачальник /
 * день-тиждень-місяць, а також окремі розрізи за типом бронювання
 * (walk_in vs scheduled) і за причинами відмов.
 */
enum Dimension: string
{
    case Network = 'network';
    case City = 'city';
    case Store = 'store';
    case Ramp = 'ramp';
    case Supplier = 'supplier';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Type = 'type';
    case RejectionReason = 'rejection_reason';

    /** Ключ групи для факту бронювання. */
    public function keyOf(BookingFact $fact): string
    {
        return match ($this) {
            self::Network => 'network',
            self::City => $fact->city,
            self::Store => $fact->storeId,
            self::Ramp => $fact->storeId . ':' . $fact->rampId(),
            self::Supplier => $fact->supplierId,
            self::Day => PeriodBucket::day($fact->slotStart),
            self::Week => PeriodBucket::week($fact->slotStart),
            self::Month => PeriodBucket::month($fact->slotStart),
            self::Type => $fact->type->value,
            self::RejectionReason => $fact->rejectedReason()?->value ?? 'none',
        };
    }

    /**
     * Ключ групи для слота. Розрізи, яких у слота немає (постачальник, тип,
     * причина відмови), не підтримуються для KPI-01.
     */
    public function keyOfSlot(SlotFact $slot): ?string
    {
        return match ($this) {
            self::Network => 'network',
            self::City => $slot->city,
            self::Store => $slot->storeId,
            self::Ramp => $slot->storeId . ':' . $slot->rampId,
            self::Day => PeriodBucket::day($slot->start),
            self::Week => PeriodBucket::week($slot->start),
            self::Month => PeriodBucket::month($slot->start),
            self::Supplier, self::Type, self::RejectionReason => null,
        };
    }

    public function supportsSlots(): bool
    {
        return match ($this) {
            self::Supplier, self::Type, self::RejectionReason => false,
            default => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Network => 'Мережа',
            self::City => 'Місто',
            self::Store => 'Магазин',
            self::Ramp => 'Рампа',
            self::Supplier => 'Постачальник',
            self::Day => 'День',
            self::Week => 'Тиждень',
            self::Month => 'Місяць',
            self::Type => 'Тип бронювання',
            self::RejectionReason => 'Причина відмови',
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
