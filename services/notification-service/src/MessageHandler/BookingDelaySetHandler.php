<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Event\BookingDelaySet;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Time\KyivTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * BookingDelaySet → SMS постачальнику про затримку (NOT-T6).
 *
 * Магазин-контур дізнається про затримку через realtime-канал (RT-02),
 * тому окремого сповіщення для нього тут немає.
 * Сповіщення НЕ входить до критичних (NOT-05) — opt-out застосовується.
 */
#[AsMessageHandler]
final readonly class BookingDelaySetHandler
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
    ) {
    }

    public function __invoke(BookingDelaySet $event): void
    {
        $payload = [
            'date' => KyivTime::formatDate($event->slotStartUtc),
            'time' => KyivTime::formatTime($event->slotStartUtc),
            'externalId' => $event->storeExternalId,
            'reason' => $event->reason,
        ];

        if (null !== $event->supplierPhone && '' !== trim($event->supplierPhone)) {
            $this->dispatcher->send(new NotificationRequest(
                template: NotificationTemplate::BookingDelayed,
                channel: NotificationChannel::Sms,
                recipient: $event->supplierPhone,
                payload: $payload,
                correlationId: $event->bookingId,
                recipientId: $event->supplierId,
                fallbackRecipient: $event->supplierEmail,
            ));
        }
    }
}
