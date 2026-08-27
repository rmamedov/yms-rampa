<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Канонічний реєстр доменних подій (DATA-16). Інших подій не існує:
 * узагальненої `BookingStatusChanged` немає, перенесення слота публікується
 * парою `BookingCreated` + `BookingCancelled`, повʼязаною полем `rescheduleOf`.
 */
enum EventType: string
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
}
