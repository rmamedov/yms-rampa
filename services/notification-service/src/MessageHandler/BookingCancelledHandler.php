<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Event\BookingCancelled;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Reminder\ReminderScheduler;
use App\Domain\Reschedule\RescheduleRegistry;
use App\Domain\Time\KyivTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * BookingCancelled → сповіщення про скасування (NOT-T5).
 *
 * NOT-16: якщо скасування є частиною перенесення слота (звʼязок
 * `rescheduleOf`), окреме NOT-T5 НЕ надсилається — постачальник і водій
 * отримають єдине NOT-T7. Звʼязок розпізнається двома шляхами: з поля самої
 * події і з реєстру перенесень, тому порядок надходження пари подій
 * не має значення.
 *
 * NOT-06: скасування завжди знімає заплановані нагадування.
 */
#[AsMessageHandler]
final readonly class BookingCancelledHandler
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private ReminderScheduler $reminders,
        private RescheduleRegistry $reschedules,
        private string $defaultPortalUrl = '',
    ) {
    }

    public function __invoke(BookingCancelled $event): void
    {
        $this->reminders->cancelForBooking($event->bookingId);

        if ($event->isReschedule()) {
            /** @var string $newBookingId */
            $newBookingId = $event->rescheduledToBookingId;
            $this->reschedules->markRescheduled($event->bookingId, $newBookingId);

            return;
        }

        if ($this->reschedules->isRescheduled($event->bookingId)) {
            return;
        }

        $payload = [
            'date' => KyivTime::formatDate($event->slotStartUtc),
            'time' => KyivTime::formatTime($event->slotStartUtc),
            'externalId' => $event->storeExternalId,
            'reason' => $event->reason,
            'url' => '' !== $event->portalUrl ? $event->portalUrl : $this->defaultPortalUrl,
        ];

        if (null !== $event->supplierEmail && '' !== trim($event->supplierEmail)) {
            $this->dispatcher->send(new NotificationRequest(
                template: NotificationTemplate::BookingCancelled,
                channel: NotificationChannel::Email,
                recipient: $event->supplierEmail,
                payload: $payload,
                correlationId: $event->bookingId,
                recipientId: $event->supplierId,
            ));
        }

        if (null !== $event->driverPhone && '' !== trim($event->driverPhone)) {
            $this->dispatcher->send(new NotificationRequest(
                template: NotificationTemplate::BookingCancelled,
                channel: NotificationChannel::Sms,
                recipient: $event->driverPhone,
                payload: $payload,
                correlationId: $event->bookingId,
                recipientId: $event->driverId,
                fallbackRecipient: $event->supplierEmail,
            ));
        }
    }
}
