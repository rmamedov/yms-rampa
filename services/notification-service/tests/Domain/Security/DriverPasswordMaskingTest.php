<?php

declare(strict_types=1);

namespace App\Tests\Domain\Security;

use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Event\DriverCreated;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Security\SecretMasker;
use App\MessageHandler\DriverCreatedHandler;
use App\Tests\Support\NotificationTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Правило безпеки NOT-15: одноразовий пароль водія НІКОЛИ не потрапляє
 * в журнали і не зберігається після відправки.
 */
#[CoversClass(SecretMasker::class)]
final class DriverPasswordMaskingTest extends TestCase
{
    private const string PASSWORD = 'Xk7m2Qp9';

    private NotificationTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new NotificationTestEnvironment();
    }

    public function testPasswordNeverAppearsInLogsAfterSuccessfulSend(): void
    {
        $this->dispatchDriverCreated();

        $log = $this->env->logger->dump();
        self::assertNotSame('', $log);
        self::assertStringNotContainsString(self::PASSWORD, $log);
        // Після успішної відправки секрет уже затертий, тож у журнал іде null.
        self::assertStringContainsString('"password":null', $log);
    }

    /**
     * Найнебезпечніший шлях: під час ретраїв пароль ще лежить у payload,
     * і саме тоді журнал міг би його видати.
     */
    public function testPasswordNeverAppearsInLogsWhenProviderFails(): void
    {
        $this->env->sms->failAlways();

        $this->dispatchDriverCreated();
        $this->env->clock->advanceMinutes(1);
        $this->env->dispatcher->processDue();
        $this->env->clock->advanceMinutes(5);
        $this->env->dispatcher->processDue();

        $log = $this->env->logger->dump();
        self::assertNotSame('', $log);
        self::assertStringNotContainsString(self::PASSWORD, $log);
        self::assertStringContainsString(SecretMasker::MASK, $log);
    }

    /**
     * NOT-15: пароль не персиститься після відправки.
     */
    public function testPasswordIsWipedFromStorageAfterSending(): void
    {
        $this->dispatchDriverCreated();

        $stored = $this->env->repository->all();
        self::assertCount(1, $stored);
        self::assertNull($stored[0]->payload()['password']);
    }

    /**
     * Але в самому SMS пароль, звісно, є — інакше водій не увійде.
     */
    public function testPasswordIsPresentInTheSmsItself(): void
    {
        $this->dispatchDriverCreated();

        $message = $this->env->sms->lastMessage();
        self::assertNotNull($message);
        self::assertStringContainsString(self::PASSWORD, $message->text);
    }

    public function testMaskerHidesKnownSensitiveKeysRecursively(): void
    {
        $masker = new SecretMasker();

        $masked = $masker->maskArray([
            'phone' => '+380671234567',
            'password' => self::PASSWORD,
            'nested' => ['accessToken' => 'abc123', 'city' => 'Київ'],
        ]);

        self::assertSame(SecretMasker::MASK, $masked['password']);
        self::assertSame(SecretMasker::MASK, $masked['nested']['accessToken']);
        self::assertSame('Київ', $masked['nested']['city']);
        self::assertSame('+380671234567', $masked['phone']);
    }

    public function testMaskerWipesSecretValueFromArbitraryText(): void
    {
        $masker = new SecretMasker();

        $text = 'Помилка провайдера для тексту з паролем '.self::PASSWORD.' у відповіді';
        $masked = $masker->maskText($text, ['password' => self::PASSWORD]);

        self::assertStringNotContainsString(self::PASSWORD, $masked);
        self::assertStringContainsString(SecretMasker::MASK, $masked);
    }

    public function testMaskerIgnoresNonSensitiveKeys(): void
    {
        $masker = new SecretMasker();

        $text = 'Місто Київ';
        self::assertSame($text, $masker->maskText($text, ['city' => 'Київ']));
    }

    /**
     * Ще один шлях витоку — журнал транспорту. NullTransport і SmsTransport
     * пишуть лише довжину, а не текст.
     */
    public function testDispatcherLogContextCarriesMaskedPayloadOnly(): void
    {
        $this->env->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::DriverPassword,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: [
                'phone' => '+380671234567',
                'password' => self::PASSWORD,
                'url' => 'https://yms.silpo.ua/d',
            ],
            correlationId: 'drv-1',
        ));

        foreach ($this->env->logger->records() as $record) {
            if (!isset($record['context']['payload'])) {
                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = $record['context']['payload'];
            self::assertNotSame(self::PASSWORD, $payload['password'] ?? null);
        }
    }

    private function dispatchDriverCreated(): void
    {
        $handler = new DriverCreatedHandler($this->env->dispatcher, 'https://yms.silpo.ua/d');

        $handler(new DriverCreated(
            driverId: 'drv-1',
            fullName: 'Петренко Іван Іванович',
            phone: '+380671234567',
            oneTimePassword: self::PASSWORD,
            loginUrl: 'https://yms.silpo.ua/d',
            supplierId: 'sup-1',
        ));
    }

    public function testHandlerUsesSmsChannelAndCriticalTemplate(): void
    {
        $this->dispatchDriverCreated();

        $stored = $this->env->repository->all();
        self::assertCount(1, $stored);
        self::assertSame(NotificationChannel::Sms, $stored[0]->channel());
        self::assertSame(NotificationTemplate::DriverPassword, $stored[0]->template());
        self::assertTrue($stored[0]->template()->isCritical());
    }
}
