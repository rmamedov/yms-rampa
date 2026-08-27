<?php

declare(strict_types=1);

namespace App\Tests\Domain\Notification;

use App\Domain\Exception\DomainException;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Security\SecretMasker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Життєвий цикл сповіщення (NOT-03) і затирання секретів (NOT-15).
 */
#[CoversClass(Notification::class)]
#[CoversClass(NotificationStatus::class)]
final class NotificationTest extends TestCase
{
    private const string NOW = '2026-09-04 09:00:00';

    public function testQueuedNotificationIsDueImmediately(): void
    {
        $notification = $this->queueSms();

        self::assertSame(NotificationStatus::Queued, $notification->status());
        self::assertSame(0, $notification->attempts());
        self::assertTrue($notification->isDue($this->at('2026-09-04 09:00:00')));
    }

    public function testEmptyRecipientIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Не вказано отримувача сповіщення.');

        Notification::queue(
            id: 'n1',
            channel: NotificationChannel::Sms,
            recipient: '   ',
            template: NotificationTemplate::DriverPassword,
            payload: [],
            now: $this->at(self::NOW),
        );
    }

    public function testSentThenDeliveredTransition(): void
    {
        $notification = $this->queueSms();
        $notification->markSent($this->at('2026-09-04 09:00:05'), 'msg-1');

        self::assertSame(NotificationStatus::Sent, $notification->status());
        self::assertSame(1, $notification->attempts());
        self::assertSame('msg-1', $notification->providerMessageId());
        self::assertNotNull($notification->sentAt());
        self::assertNull($notification->nextAttemptAt());

        $notification->markDelivered();
        self::assertSame(NotificationStatus::Delivered, $notification->status());
        self::assertTrue($notification->status()->isFinal());
    }

    public function testDeliveredCannotBeChangedAgain(): void
    {
        $notification = $this->queueSms();
        $notification->markSent($this->at('2026-09-04 09:00:05'));
        $notification->markDelivered();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('термінальному статусі');

        $notification->markFailed('пізня помилка', $this->at('2026-09-04 09:10:00'));
    }

    public function testDeliveryConfirmationRequiresSentStatus(): void
    {
        $notification = $this->queueSms();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Позначити доставленим можна лише сповіщення у статусі sent.');

        $notification->markDelivered();
    }

    public function testFailedAttemptKeepsNotificationInQueue(): void
    {
        $notification = $this->queueSms();
        $notification->registerFailedAttempt(
            'Провайдер недоступний',
            $this->at('2026-09-04 09:00:05'),
            $this->at('2026-09-04 09:01:05'),
        );

        self::assertSame(NotificationStatus::Queued, $notification->status());
        self::assertSame(1, $notification->attempts());
        self::assertSame('Провайдер недоступний', $notification->error());
        self::assertFalse($notification->isDue($this->at('2026-09-04 09:00:30')));
        self::assertTrue($notification->isDue($this->at('2026-09-04 09:01:05')));
    }

    /**
     * NOT-15: після відправки пароль не лишається у сховищі.
     */
    public function testForgetSecretsClearsPasswordOnly(): void
    {
        $notification = $this->queueSms();
        $notification->markSent($this->at('2026-09-04 09:00:05'));
        $notification->forgetSecrets();

        $payload = $notification->payload();
        self::assertNull($payload['password']);
        self::assertSame('+380671234567', $payload['phone']);
    }

    public function testMaskedPayloadHidesPassword(): void
    {
        $notification = $this->queueSms();

        $masked = $notification->maskedPayload(new SecretMasker());

        self::assertSame(SecretMasker::MASK, $masked['password']);
        self::assertSame('+380671234567', $masked['phone']);
    }

    public function testExpiredNotificationIsFinal(): void
    {
        $notification = $this->queueSms();
        $notification->markExpired('Час нагадування минув');

        self::assertSame(NotificationStatus::Expired, $notification->status());
        self::assertTrue($notification->status()->isFinal());
        self::assertFalse($notification->status()->isSuccessful());
    }

    private function queueSms(): Notification
    {
        return Notification::queue(
            id: 'n1',
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            template: NotificationTemplate::DriverPassword,
            payload: [
                'phone' => '+380671234567',
                'password' => 'Xk7m2Qp9',
                'url' => 'https://yms.silpo.ua/d',
            ],
            now: $this->at(self::NOW),
            correlationId: 'drv-1',
            recipientId: 'drv-1',
        );
    }

    private function at(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));
    }
}
