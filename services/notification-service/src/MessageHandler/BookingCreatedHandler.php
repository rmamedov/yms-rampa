<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Event\BookingCreated;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Reminder\ReminderPlan;
use App\Domain\Reminder\ReminderScheduler;
use App\Domain\Reschedule\RescheduleRegistry;
use App\Domain\Time\KyivTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * BookingCreated → підтвердження бронювання (NOT-T2) або, якщо це друга
 * половина пари перенесення, — єдине сповіщення про перенесення (NOT-T7).
 *
 * NOT-16: за наявності `rescheduleOf` окремі NOT-T2 і NOT-T5 не надсилаються.
 * NOT-06: одночасно плануються нагадування NOT-T3 і NOT-T4; при перенесенні
 * нагадування старого бронювання знімаються.
 */
#[AsMessageHandler]
final readonly class BookingCreatedHandler
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private ReminderScheduler $reminders,
        private RescheduleRegistry $reschedules,
        private string $defaultPortalUrl = '',
    ) {
    }

    public function __invoke(BookingCreated $event): void
    {
        $date = KyivTime::formatDate($event->slotStartUtc);
        $time = KyivTime::formatTime($event->slotStartUtc);
        $url = '' !== $event->portalUrl ? $event->portalUrl : $this->defaultPortalUrl;

        if ($event->isReschedule()) {
            /** @var string $oldBookingId */
            $oldBookingId = $event->rescheduleOf;
            $this->reschedules->markRescheduled($oldBookingId, $event->bookingId);
            // Нагадування старого бронювання переплановуються на новий слот (NOT-06).
            $this->reminders->cancelForBooking($oldBookingId);

            $payload = [
                'date' => $date,
                'time' => $time,
                'externalId' => $event->storeExternalId,
                'rampNumber' => $event->rampNumber,
                'url' => $url,
            ];

            $this->notify(
                NotificationTemplate::BookingRescheduled,
                $payload,
                $event->supplierEmail,
                $event->supplierPhone,
                $event->driverPhone,
                $event->bookingId,
                $event->supplierId,
                $event->driverId,
            );
        } else {
            $payload = [
                'date' => $date,
                'time' => $time,
                'externalId' => $event->storeExternalId,
                'city' => $event->city,
                'address' => $event->address,
                'rampNumber' => $event->rampNumber,
                'vehicleNumber' => $event->vehicleNumber,
                'orderId' => $event->orderId,
            ];

            $this->notify(
                NotificationTemplate::BookingConfirmed,
                $payload,
                $event->supplierEmail,
                $event->supplierPhone,
                $event->driverPhone,
                $event->bookingId,
                $event->supplierId,
                $event->driverId,
            );
        }

        $this->reminders->scheduleForBooking(new ReminderPlan(
            bookingId: $event->bookingId,
            slotStartUtc: $event->slotStartUtc,
            storeExternalId: $event->storeExternalId,
            address: $event->address,
            rampNumber: $event->rampNumber,
            driverPhone: $event->driverPhone,
            driverId: $event->driverId,
            supplierEmail: $event->supplierEmail,
            supplierId: $event->supplierId,
        ));
    }

    /**
     * NOT-T2 і NOT-T7 ідуть постачальнику (SMS + e-mail) і водію,
     * якщо його призначено.
     *
     * @param array<string, scalar|\Stringable|null> $payload
     */
    private function notify(
        NotificationTemplate $template,
        array $payload,
        ?string $supplierEmail,
        ?string $supplierPhone,
        ?string $driverPhone,
        string $bookingId,
        ?string $supplierId,
        ?string $driverId,
    ): void {
        if (null !== $supplierEmail && '' !== trim($supplierEmail)) {
            $this->dispatcher->send(new NotificationRequest(
                template: $template,
                channel: NotificationChannel::Email,
                recipient: $supplierEmail,
                payload: $payload,
                correlationId: $bookingId,
                recipientId: $supplierId,
                fallbackRecipient: $supplierPhone,
            ));
        }

        if (null !== $supplierPhone && '' !== trim($supplierPhone)) {
            $this->dispatcher->send(new NotificationRequest(
                template: $template,
                channel: NotificationChannel::Sms,
                recipient: $supplierPhone,
                payload: $payload,
                correlationId: $bookingId,
                recipientId: $supplierId,
                fallbackRecipient: $supplierEmail,
            ));
        }

        if (null !== $driverPhone && '' !== trim($driverPhone)) {
            $this->dispatcher->send(new NotificationRequest(
                template: $template,
                channel: NotificationChannel::Sms,
                recipient: $driverPhone,
                payload: $payload,
                correlationId: $bookingId,
                recipientId: $driverId,
            ));
        }
    }
}
