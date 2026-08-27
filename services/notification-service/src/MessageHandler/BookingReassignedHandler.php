<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Event\BookingReassigned;
use App\Domain\Event\ReassignmentInitiator;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Time\KyivTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * BookingReassigned → сповіщення про зміну рампи/авто/водія (NOT-T9, NOT-18).
 *
 * - зміна рампи магазином: SMS водію + e-mail постачальнику;
 * - зміна водія/авто постачальником: SMS новому водію, e-mail постачальнику
 *   не дублюється — він і є ініціатор.
 */
#[AsMessageHandler]
final readonly class BookingReassignedHandler
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private string $defaultPortalUrl = '',
    ) {
    }

    public function __invoke(BookingReassigned $event): void
    {
        if (!$event->hasChanges()) {
            return;
        }

        $payload = [
            'date' => KyivTime::formatDate($event->slotStartUtc),
            'time' => KyivTime::formatTime($event->slotStartUtc),
            'externalId' => $event->storeExternalId,
            'changes' => $event->changesDescription(),
            'url' => '' !== $event->portalUrl ? $event->portalUrl : $this->defaultPortalUrl,
        ];

        if (null !== $event->driverPhone && '' !== trim($event->driverPhone)) {
            $this->dispatcher->send(new NotificationRequest(
                template: NotificationTemplate::BookingReassigned,
                channel: NotificationChannel::Sms,
                recipient: $event->driverPhone,
                payload: $payload,
                correlationId: $event->bookingId,
                recipientId: $event->driverId,
                fallbackRecipient: $event->supplierEmail,
            ));
        }

        // NOT-18: ініціатору-постачальнику лист не дублюємо.
        if (ReassignmentInitiator::Store !== $event->initiator) {
            return;
        }

        if (null !== $event->supplierEmail && '' !== trim($event->supplierEmail)) {
            $this->dispatcher->send(new NotificationRequest(
                template: NotificationTemplate::BookingReassigned,
                channel: NotificationChannel::Email,
                recipient: $event->supplierEmail,
                payload: $payload,
                correlationId: $event->bookingId,
                recipientId: $event->supplierId,
            ));
        }
    }
}
