<?php

declare(strict_types=1);

namespace App\Domain\Projection;

/**
 * Канонічні доменні події YMS «Рампа». Інших подій у системі не існує.
 * analytics-service будує read-моделі саме з цього потоку (KPI-05).
 */
enum DomainEventName: string
{
    case BranchSynced = 'BranchSynced';
    case StoreConfigChanged = 'StoreConfigChanged';
    case BookingCreated = 'BookingCreated';
    case SlotReleased = 'SlotReleased';
    case BookingArrived = 'BookingArrived';
    case UnloadingStarted = 'UnloadingStarted';
    case UnloadingCompleted = 'UnloadingCompleted';
    case BookingCancelled = 'BookingCancelled';
    case BookingNoShow = 'BookingNoShow';
    case BookingRejected = 'BookingRejected';
    case BookingDelaySet = 'BookingDelaySet';
    case BookingReassigned = 'BookingReassigned';
    case DriverCreated = 'DriverCreated';
    case SupplierSuspended = 'SupplierSuspended';

    /** Події, що змінюють факт бронювання. */
    public function affectsBookingFact(): bool
    {
        return match ($this) {
            self::BookingCreated,
            self::BookingArrived,
            self::UnloadingStarted,
            self::UnloadingCompleted,
            self::BookingCancelled,
            self::BookingNoShow,
            self::BookingRejected,
            self::BookingDelaySet,
            self::BookingReassigned => true,
            default => false,
        };
    }
}
