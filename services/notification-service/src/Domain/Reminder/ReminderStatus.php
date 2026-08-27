<?php

declare(strict_types=1);

namespace App\Domain\Reminder;

/**
 * Стан запланованого нагадування (NOT-06).
 */
enum ReminderStatus: string
{
    case Scheduled = 'scheduled';
    case Sent = 'sent';
    /** Знято через BookingCancelled або SlotReleased. */
    case Cancelled = 'cancelled';
    /** Не ставилося в чергу: часу до слота вже менше, ніж інтервал нагадування. */
    case Skipped = 'skipped';
}
