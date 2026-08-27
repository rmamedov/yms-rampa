<?php

declare(strict_types=1);

namespace App\Domain\Reminder;

/**
 * Сховище запланованих нагадувань (NOT-06).
 */
interface ScheduledReminderRepository
{
    public function save(ScheduledReminder $reminder): void;

    /** @return list<ScheduledReminder> */
    public function findDue(\DateTimeImmutable $now, int $limit = 100): array;

    /** @return list<ScheduledReminder> */
    public function findByBookingId(string $bookingId): array;

    public function nextIdentity(): string;
}
