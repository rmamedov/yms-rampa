<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Event\BookingRejected;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Reminder\ReminderScheduler;
use App\Domain\Time\KyivTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * BookingRejected → негайний e-mail постачальнику (NOT-T8, NOT-17).
 *
 * Сповіщення водію про відмову — фаза 2, поза MVP.
 * Сповіщення критичне: opt-out не застосовується (NOT-05).
 */
#[AsMessageHandler]
final readonly class BookingRejectedHandler
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private ReminderScheduler $reminders,
        private string $defaultPortalUrl = '',
    ) {
    }

    public function __invoke(BookingRejected $event): void
    {
        // Машину вже розвернули — нагадування по цьому бронюванню не потрібні.
        $this->reminders->cancelForBooking($event->bookingId);

        if (null === $event->supplierEmail || '' === trim($event->supplierEmail)) {
            return;
        }

        $this->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::BookingRejected,
            channel: NotificationChannel::Email,
            recipient: $event->supplierEmail,
            payload: [
                'date' => KyivTime::formatDate($event->slotStartUtc),
                'time' => KyivTime::formatTime($event->slotStartUtc),
                'externalId' => $event->storeExternalId,
                'vehicleNumber' => $event->vehicleNumber,
                'reason' => $event->reason,
                'comment' => $event->comment,
                'url' => '' !== $event->portalUrl ? $event->portalUrl : $this->defaultPortalUrl,
            ],
            correlationId: $event->bookingId,
            recipientId: $event->supplierId,
        ));
    }
}
