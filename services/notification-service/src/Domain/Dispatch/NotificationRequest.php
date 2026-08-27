<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;

/**
 * Заявка на сповіщення — те, що обробники подій передають диспетчеру.
 *
 * `recipientId` потрібен для перевірки opt-out (NOT-05),
 * `fallbackRecipient` — для дублювання критичних сповіщень
 * резервним каналом (NOT-04),
 * `correlationId` — це bookingId або driverId для журналу.
 */
final readonly class NotificationRequest
{
    /** @param array<string, scalar|\Stringable|null> $payload */
    public function __construct(
        public NotificationTemplate $template,
        public NotificationChannel $channel,
        public string $recipient,
        public array $payload,
        public ?string $correlationId = null,
        public ?string $recipientId = null,
        public ?string $fallbackRecipient = null,
    ) {
    }
}
