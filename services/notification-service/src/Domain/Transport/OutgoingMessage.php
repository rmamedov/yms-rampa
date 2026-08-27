<?php

declare(strict_types=1);

namespace App\Domain\Transport;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\RenderedMessage;

/**
 * Те, що транспорт реально віддає провайдеру.
 *
 * Навмисно не містить payload сповіщення — транспорт не має доступу
 * до сирих даних, зокрема до пароля водія (NOT-15).
 */
final readonly class OutgoingMessage
{
    public function __construct(
        public string $notificationId,
        public NotificationChannel $channel,
        public string $recipient,
        public string $text,
        public ?string $subject = null,
        public ?string $html = null,
        public string $templateCode = '',
    ) {
    }

    public static function fromRendered(
        string $notificationId,
        string $recipient,
        RenderedMessage $rendered,
    ): self {
        return new self(
            notificationId: $notificationId,
            channel: $rendered->channel,
            recipient: $recipient,
            text: $rendered->text,
            subject: $rendered->subject,
            html: $rendered->html,
            templateCode: $rendered->template->code(),
        );
    }
}
