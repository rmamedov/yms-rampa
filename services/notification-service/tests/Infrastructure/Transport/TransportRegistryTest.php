<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Transport;

use App\Domain\Exception\NotImplementedException;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\TransportException;
use App\Infrastructure\Email\EmailTransport;
use App\Infrastructure\InMemory\InMemoryTransport;
use App\Infrastructure\Transport\ChannelTransportRegistry;
use App\Infrastructure\Transport\NullTransport;
use App\Infrastructure\Transport\ViberTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Реєстр транспортів, заглушки та e-mail через Symfony Mailer (NOT-01).
 */
#[CoversClass(ChannelTransportRegistry::class)]
#[CoversClass(NullTransport::class)]
#[CoversClass(ViberTransport::class)]
#[CoversClass(EmailTransport::class)]
final class TransportRegistryTest extends TestCase
{
    public function testRegistryResolvesTransportByChannel(): void
    {
        $sms = new InMemoryTransport([NotificationChannel::Sms], 'sms');
        $email = new InMemoryTransport([NotificationChannel::Email], 'email');
        $registry = new ChannelTransportRegistry([$sms, $email, new ViberTransport()]);

        self::assertSame($sms, $registry->for(NotificationChannel::Sms));
        self::assertSame($email, $registry->for(NotificationChannel::Email));
        self::assertTrue($registry->has(NotificationChannel::Viber));
    }

    public function testMissingTransportIsPermanentFailure(): void
    {
        $registry = new ChannelTransportRegistry([new InMemoryTransport([NotificationChannel::Sms], 'sms')]);

        self::assertFalse($registry->has(NotificationChannel::Email));

        try {
            $registry->for(NotificationChannel::Email);
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('не налаштовано жодного транспорту', $e->getMessage());
        }
    }

    /**
     * NOT-01: Viber — заділ на фазу 2.
     */
    public function testViberTransportThrowsNotImplemented(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->expectExceptionMessage('Канал Viber заплановано на фазу 2');

        (new ViberTransport())->send(new OutgoingMessage(
            notificationId: 'n1',
            channel: NotificationChannel::Viber,
            recipient: '+380671234567',
            text: 'Текст',
        ));
    }

    public function testNullTransportAcceptsButSendsNothing(): void
    {
        $transport = new NullTransport();

        $receipt = $transport->send(new OutgoingMessage(
            notificationId: 'n1',
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            text: 'Текст',
        ));

        self::assertSame('null', $receipt->provider);
        self::assertSame('null-n1', $receipt->providerMessageId);
        self::assertTrue($transport->supports(NotificationChannel::Email));
    }

    public function testEmailTransportBuildsMessageWithSubjectAndHtml(): void
    {
        $sentEmails = [];
        $mailer = new class($sentEmails) implements MailerInterface {
            /** @param list<RawMessage> $sent */
            public function __construct(public array &$sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };

        $transport = new EmailTransport($mailer, 'yms@silpo.ua');
        $receipt = $transport->send(new OutgoingMessage(
            notificationId: 'n1',
            channel: NotificationChannel::Email,
            recipient: 'supplier@example.com',
            text: 'Бронювання підтверджено',
            subject: 'Бронювання підтверджено — філія №1998',
            html: '<p>Бронювання підтверджено</p>',
            templateCode: 'NOT-T2',
        ));

        self::assertCount(1, $sentEmails);
        $email = $sentEmails[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Бронювання підтверджено — філія №1998', $email->getSubject());
        self::assertSame('supplier@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('yms@silpo.ua', $email->getFrom()[0]->getAddress());
        self::assertSame('symfony-mailer', $receipt->provider);
    }

    public function testEmailTransportRejectsMalformedAddress(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
            }
        };

        try {
            (new EmailTransport($mailer, 'yms@silpo.ua'))->send(new OutgoingMessage(
                notificationId: 'n1',
                channel: NotificationChannel::Email,
                recipient: 'це-не-адреса',
                text: 'Текст',
                subject: 'Тема',
            ));
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('Некоректна e-mail адреса', $e->getMessage());
        }
    }
}
