<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Sms;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\TransportException;
use App\Infrastructure\InMemory\InMemorySmsProvider;
use App\Infrastructure\Sms\NullSmsProvider;
use App\Infrastructure\Sms\SmsProviderFactory;
use App\Infrastructure\Sms\SmsTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SMS-транспорт: нормалізація номера, ліміт NOT-07 і вибір провайдера
 * env-конфігом (NOT-01).
 */
#[CoversClass(SmsTransport::class)]
final class SmsTransportTest extends TestCase
{
    private InMemorySmsProvider $provider;
    private SmsTransport $transport;

    protected function setUp(): void
    {
        $this->provider = new InMemorySmsProvider();
        $this->transport = new SmsTransport($this->provider);
    }

    public function testSupportsOnlySmsChannel(): void
    {
        self::assertTrue($this->transport->supports(NotificationChannel::Sms));
        self::assertFalse($this->transport->supports(NotificationChannel::Email));
        self::assertFalse($this->transport->supports(NotificationChannel::Viber));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phoneNumbers(): iterable
    {
        yield 'міжнародний з плюсом' => ['+380671234567', '380671234567'];
        yield 'з пробілами й дефісами' => ['+38 (067) 123-45-67', '380671234567'];
        yield 'національний з нуля' => ['0671234567', '380671234567'];
        yield 'без плюса' => ['380671234567', '380671234567'];
    }

    #[DataProvider('phoneNumbers')]
    public function testPhoneIsNormalisedForProvider(string $input, string $expected): void
    {
        $this->transport->send($this->message('Коротке повідомлення', $input));

        self::assertSame($expected, $this->provider->sentMessages()[0]['phone']);
    }

    public function testInvalidPhoneIsPermanentFailure(): void
    {
        try {
            $this->transport->send($this->message('Текст', '12345'));
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('Некоректний номер телефону', $e->getMessage());
        }
    }

    /**
     * NOT-07: більше 3 сегментів провайдер не приймає.
     */
    public function testTooLongSmsIsRejectedWithoutRetry(): void
    {
        try {
            $this->transport->send($this->message(str_repeat('я', 300), '+380671234567'));
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('перевищує ліміт NOT-07', $e->getMessage());
        }

        self::assertSame([], $this->provider->sentMessages());
    }

    public function testProviderFailureIsRetryable(): void
    {
        $this->provider->failNextTimes(1);

        try {
            $this->transport->send($this->message('Текст', '+380671234567'));
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testTransportNameIncludesProvider(): void
    {
        self::assertSame('sms:in-memory-sms', $this->transport->name());
    }

    /**
     * NOT-01: провайдер обирається конфігом, код не змінюється.
     */
    public function testFactorySelectsProviderByConfiguredName(): void
    {
        $turbo = $this->turboSmsStub();
        $esputnik = $this->eSputnikStub();
        $null = new NullSmsProvider();

        self::assertSame('turbosms', (new SmsProviderFactory($turbo, $esputnik, $null, 'turbosms'))->create()->name());
        self::assertSame('esputnik', (new SmsProviderFactory($turbo, $esputnik, $null, 'eSputnik'))->create()->name());
        self::assertSame('null', (new SmsProviderFactory($turbo, $esputnik, $null, ''))->create()->name());
        self::assertSame('null', (new SmsProviderFactory($turbo, $esputnik, $null, 'невідомий'))->create()->name());
    }

    private function turboSmsStub(): \App\Infrastructure\Sms\TurboSmsProvider
    {
        return new \App\Infrastructure\Sms\TurboSmsProvider(
            new \Symfony\Component\HttpClient\MockHttpClient(),
            'https://api.turbosms.ua/message/send.json',
            'token',
            'Silpo',
        );
    }

    private function eSputnikStub(): \App\Infrastructure\Sms\ESputnikSmsProvider
    {
        return new \App\Infrastructure\Sms\ESputnikSmsProvider(
            new \Symfony\Component\HttpClient\MockHttpClient(),
            'https://esputnik.com/api/v1/message/sms',
            'token',
            'Silpo',
        );
    }

    private function message(string $text, string $recipient): OutgoingMessage
    {
        return new OutgoingMessage(
            notificationId: 'n1',
            channel: NotificationChannel::Sms,
            recipient: $recipient,
            text: $text,
            templateCode: 'NOT-T2',
        );
    }
}
