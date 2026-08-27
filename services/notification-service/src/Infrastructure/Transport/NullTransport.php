<?php

declare(strict_types=1);

namespace App\Infrastructure\Transport;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Transport\NotificationTransport;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\TransportReceipt;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Транспорт-заглушка: нічого нікуди не шле, лише пише в журнал.
 *
 * Використовується в dev/стенді, щоб не витрачати SMS-баланс і не
 * турбувати реальних водіїв. Текст повідомлення в журнал НЕ пишеться:
 * шаблон NOT-T1 містить пароль (NOT-15).
 */
final readonly class NullTransport implements NotificationTransport
{
    /** @param list<NotificationChannel> $channels */
    public function __construct(
        private array $channels = [NotificationChannel::Sms, NotificationChannel::Email],
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(NotificationChannel $channel): bool
    {
        return \in_array($channel, $this->channels, true);
    }

    public function send(OutgoingMessage $message): TransportReceipt
    {
        $this->logger->info('NullTransport: відправку пропущено', [
            'notificationId' => $message->notificationId,
            'template' => $message->templateCode,
            'channel' => $message->channel->value,
            'recipient' => $message->recipient,
            'length' => mb_strlen($message->text, 'UTF-8'),
        ]);

        return new TransportReceipt(providerMessageId: 'null-'.$message->notificationId, provider: 'null');
    }

    public function name(): string
    {
        return 'null';
    }
}
