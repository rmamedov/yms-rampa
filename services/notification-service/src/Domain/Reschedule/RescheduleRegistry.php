<?php

declare(strict_types=1);

namespace App\Domain\Reschedule;

/**
 * Звʼязок «старе бронювання → нове» для перенесення слота (NOT-16).
 *
 * Окремої події «перенесення» не існує: воно виводиться з пари
 * BookingCreated + BookingCancelled, повʼязаної полем `rescheduleOf`.
 * Реєстр дає змогу коректно обробити пару в будь-якому порядку надходження:
 * якщо про перенесення вже відомо, окремі NOT-T5 і NOT-T2 не надсилаються.
 */
interface RescheduleRegistry
{
    public function markRescheduled(string $cancelledBookingId, string $newBookingId): void;

    public function isRescheduled(string $cancelledBookingId): bool;

    public function newBookingFor(string $cancelledBookingId): ?string;
}
