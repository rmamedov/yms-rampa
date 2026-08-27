<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Event\SlotReleased;
use App\Domain\Reminder\ReminderScheduler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * SlotReleased → знімає заплановані нагадування бронювання, яке більше
 * не займає слот (NOT-02, NOT-06).
 *
 * Власного шаблону подія не має: сповіщення про звільнення слота
 * розділом 11.2.2 не передбачене.
 */
#[AsMessageHandler]
final readonly class SlotReleasedHandler
{
    public function __construct(
        private ReminderScheduler $reminders,
    ) {
    }

    public function __invoke(SlotReleased $event): void
    {
        if (null === $event->bookingId || '' === trim($event->bookingId)) {
            return;
        }

        $this->reminders->cancelForBooking($event->bookingId);
    }
}
