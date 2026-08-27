<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dispatch;

use App\Domain\Dispatch\DispatchResult;
use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Notification\TemplateSamples;
use App\Tests\Support\NotificationTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Диспетчер відправки: ретраї з backoff (NOT-04), резервний канал,
 * opt-out (NOT-05) і незагубленість повідомлень при збої провайдера.
 */
#[CoversClass(NotificationDispatcher::class)]
final class NotificationDispatcherTest extends TestCase
{
    private NotificationTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new NotificationTestEnvironment();
    }

    public function testSuccessfulSendMarksNotificationAsSent(): void
    {
        $notification = $this->env->dispatcher->send($this->confirmationRequest());

        self::assertNotNull($notification);
        self::assertSame(NotificationStatus::Sent, $notification->status());
        self::assertSame(1, $notification->attempts());
        self::assertSame(1, $this->env->sms->sentCount());
        self::assertNotNull($notification->sentAt());
        self::assertNotNull($notification->providerMessageId());
    }

    public function testRenderedTextReachesTransport(): void
    {
        $this->env->dispatcher->send($this->confirmationRequest());

        $message = $this->env->sms->lastMessage();
        self::assertNotNull($message);
        self::assertStringContainsString('Бронювання підтверджено: 05.09.2026 14:30', $message->text);
        self::assertSame('NOT-T2', $message->templateCode);
    }

    /**
     * NOT-04: перша невдача не втрачає повідомлення — воно лишається
     * в черзі з датою наступної спроби через 1 хв.
     */
    public function testFailedAttemptSchedulesRetryWithBackoff(): void
    {
        $this->env->sms->failNextTimes(1);

        $notification = $this->env->dispatcher->send($this->confirmationRequest());

        self::assertNotNull($notification);
        self::assertSame(NotificationStatus::Queued, $notification->status());
        self::assertSame(1, $notification->attempts());
        self::assertSame(
            '2026-09-04 09:01:00',
            $notification->nextAttemptAt()?->format('Y-m-d H:i:s'),
        );
        self::assertSame(0, $this->env->sms->sentCount());
    }

    /**
     * Другий інтервал backoff — 5 хв.
     */
    public function testSecondFailureUsesFiveMinuteInterval(): void
    {
        $this->env->sms->failNextTimes(2);

        $notification = $this->env->dispatcher->send($this->confirmationRequest());
        self::assertNotNull($notification);

        $this->env->clock->advanceMinutes(1);
        self::assertSame(DispatchResult::Retrying, $this->env->dispatcher->dispatch($notification));

        self::assertSame(2, $notification->attempts());
        self::assertSame(
            '2026-09-04 09:06:00',
            $notification->nextAttemptAt()?->format('Y-m-d H:i:s'),
        );
    }

    /**
     * NOT-04: після вичерпання спроб — статус failed і запис у лог.
     */
    public function testAttemptsAreExhaustedAndNotificationFails(): void
    {
        $this->env->sms->failAlways();

        $notification = $this->env->dispatcher->send($this->confirmationRequest());
        self::assertNotNull($notification);

        $this->env->clock->advanceMinutes(1);
        $this->env->dispatcher->dispatch($notification);
        $this->env->clock->advanceMinutes(5);
        $result = $this->env->dispatcher->dispatch($notification);

        self::assertSame(DispatchResult::Failed, $result);
        self::assertSame(NotificationStatus::Failed, $notification->status());
        self::assertSame(3, $notification->attempts());
        self::assertStringContainsString('Сповіщення не доставлено', $this->env->logger->dump());
    }

    /**
     * Ключова вимога NOT-04: недоступність провайдера не губить повідомлення —
     * після відновлення воно доходить тим самим прогоном черги.
     */
    public function testProviderOutageDoesNotLoseTheMessage(): void
    {
        $this->env->sms->failNextTimes(2);

        $this->env->dispatcher->send($this->confirmationRequest());
        self::assertSame(1, $this->env->repository->count());

        $this->env->clock->advanceMinutes(1);
        self::assertSame(['sent' => 0, 'retrying' => 1, 'failed' => 0, 'skipped' => 0], $this->env->dispatcher->processDue());

        $this->env->clock->advanceMinutes(5);
        self::assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'skipped' => 0], $this->env->dispatcher->processDue());

        self::assertSame(1, $this->env->sms->sentCount());
        self::assertSame(1, $this->env->repository->count());
    }

    public function testRetryIsNotAttemptedBeforeItsTime(): void
    {
        $this->env->sms->failNextTimes(1);
        $this->env->dispatcher->send($this->confirmationRequest());

        $this->env->clock->advanceSeconds(30);

        self::assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'skipped' => 0], $this->env->dispatcher->processDue());
        self::assertSame(0, $this->env->sms->sentCount());
    }

    /**
     * Невиправна помилка (невалідний отримувач) не ретраїться.
     */
    public function testPermanentFailureIsNotRetried(): void
    {
        $this->env->sms->failPermanently();

        $notification = $this->env->dispatcher->send($this->confirmationRequest());

        self::assertNotNull($notification);
        self::assertSame(NotificationStatus::Failed, $notification->status());
        self::assertSame(1, $notification->attempts());
    }

    /**
     * NOT-04: критичне сповіщення, що остаточно впало, дублюється
     * резервним каналом, якщо адреса заповнена.
     */
    public function testCriticalNotificationIsDuplicatedToFallbackChannel(): void
    {
        $this->env->sms->failPermanently();

        $this->env->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::BookingCancelled,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: TemplateSamples::for(NotificationTemplate::BookingCancelled),
            correlationId: 'bkg-1',
            fallbackRecipient: 'supplier@example.com',
        ));

        self::assertSame(2, $this->env->repository->count());

        $this->env->dispatcher->processDue();

        self::assertSame(1, $this->env->email->sentCount());
        self::assertSame('supplier@example.com', $this->env->email->lastMessage()?->recipient);
    }

    public function testNonCriticalNotificationIsNotDuplicated(): void
    {
        $this->env->sms->failPermanently();

        $this->env->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::BookingDelayed,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: TemplateSamples::for(NotificationTemplate::BookingDelayed),
            correlationId: 'bkg-1',
            fallbackRecipient: 'supplier@example.com',
        ));

        self::assertSame(1, $this->env->repository->count());
    }

    /**
     * NOT-05: некритичні сповіщення можна вимкнути в профілі.
     */
    public function testOptOutSuppressesNonCriticalNotification(): void
    {
        $this->env->optOut->optOutAll('sup-1');

        $notification = $this->env->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::Reminder24h,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: TemplateSamples::for(NotificationTemplate::Reminder24h),
            recipientId: 'sup-1',
        ));

        self::assertNull($notification);
        self::assertSame(0, $this->env->repository->count());
        self::assertSame(0, $this->env->sms->sentCount());
    }

    /**
     * NOT-05: критичні сповіщення вимкнути неможливо.
     */
    public function testOptOutDoesNotSuppressCriticalNotification(): void
    {
        $this->env->optOut->optOutAll('sup-1');

        $notification = $this->env->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::BookingConfirmed,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: TemplateSamples::for(NotificationTemplate::BookingConfirmed),
            recipientId: 'sup-1',
        ));

        self::assertNotNull($notification);
        self::assertSame(NotificationStatus::Sent, $notification->status());
    }

    public function testTemplateRenderingErrorFailsWithoutContactingProvider(): void
    {
        $payload = TemplateSamples::for(NotificationTemplate::BookingCancelled);
        unset($payload['reason']);

        $notification = $this->env->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::BookingCancelled,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: $payload,
        ));

        self::assertNotNull($notification);
        self::assertSame(NotificationStatus::Failed, $notification->status());
        self::assertStringContainsString('Помилка рендерингу шаблону', (string) $notification->error());
        self::assertSame(0, $this->env->sms->sentCount());
    }

    /**
     * NOT-03: delivery-report провайдера переводить сповіщення в delivered.
     */
    public function testDeliveryReportMovesNotificationToDelivered(): void
    {
        $notification = $this->env->dispatcher->send($this->confirmationRequest());
        self::assertNotNull($notification);

        self::assertTrue($this->env->dispatcher->confirmDelivery($notification->id()));
        self::assertSame(NotificationStatus::Delivered, $notification->status());

        // Повторне підтвердження вже нічого не змінює.
        self::assertFalse($this->env->dispatcher->confirmDelivery($notification->id()));
        self::assertFalse($this->env->dispatcher->confirmDelivery('невідомий-id'));
    }

    public function testFinalNotificationIsSkippedOnSecondDispatch(): void
    {
        $notification = $this->env->dispatcher->send($this->confirmationRequest());
        self::assertNotNull($notification);
        $notification->markDelivered();

        self::assertSame(DispatchResult::Skipped, $this->env->dispatcher->dispatch($notification));
    }

    private function confirmationRequest(): NotificationRequest
    {
        return new NotificationRequest(
            template: NotificationTemplate::BookingConfirmed,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: TemplateSamples::for(NotificationTemplate::BookingConfirmed),
            correlationId: 'bkg-1',
        );
    }
}
