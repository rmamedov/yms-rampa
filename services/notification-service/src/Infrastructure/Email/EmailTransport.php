<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Transport\NotificationTransport;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\TransportException;
use App\Domain\Transport\TransportReceipt;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface as MailerTransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * E-mail через Symfony Mailer (NOT-01).
 *
 * Конкретний бекенд (SMTP чи Sendgrid) задається MAILER_DSN — код
 * від цього не залежить. Факт прийому SMTP-сервером вважається `sent`;
 * `delivered` проставляється пізніше за webhook провайдера (NOT-03).
 */
final readonly class EmailTransport implements NotificationTransport
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromAddress,
        private string $fromName = 'Сільпо YMS «Рампа»',
    ) {
    }

    public function supports(NotificationChannel $channel): bool
    {
        return NotificationChannel::Email === $channel;
    }

    public function send(OutgoingMessage $message): TransportReceipt
    {
        try {
            $email = (new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($message->recipient)
                ->subject($message->subject ?? 'Сільпо YMS «Рампа»')
                ->text($message->text);

            if (null !== $message->html) {
                $email->html($message->html);
            }

            $this->mailer->send($email);
        } catch (RfcComplianceException $e) {
            throw TransportException::permanent(
                \sprintf('Некоректна e-mail адреса отримувача: «%s».', $message->recipient),
                $e,
            );
        } catch (MailerTransportException $e) {
            throw new TransportException('Помилка відправки листа: '.$e->getMessage(), true, $e);
        }

        return new TransportReceipt(
            providerMessageId: 'mail-'.$message->notificationId,
            provider: 'symfony-mailer',
        );
    }

    public function name(): string
    {
        return 'email';
    }
}
