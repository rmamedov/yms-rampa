<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Event\DriverCreated;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * DriverCreated → SMS з паролем (NOT-T1, NOT-15).
 *
 * Пароль приходить у payload події; сервіс його не генерує, не пише
 * в журнал і не зберігає після відправки. Сповіщення критичне —
 * opt-out не застосовується (NOT-05).
 */
#[AsMessageHandler]
final readonly class DriverCreatedHandler
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private string $defaultPortalUrl = '',
    ) {
    }

    public function __invoke(DriverCreated $event): void
    {
        if ('' === trim($event->phone)) {
            return;
        }

        $this->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::DriverPassword,
            channel: NotificationChannel::Sms,
            recipient: $event->phone,
            payload: [
                'phone' => $event->phone,
                'password' => $event->oneTimePassword,
                'url' => '' !== $event->loginUrl ? $event->loginUrl : $this->defaultPortalUrl,
            ],
            correlationId: $event->driverId,
            recipientId: $event->driverId,
        ));
    }
}
