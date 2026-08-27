<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Sms;

use App\Domain\Transport\TransportException;
use App\Infrastructure\Sms\TurboSmsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Адаптер TurboSMS (NOT-01) і класифікація помилок для ретраїв (NOT-04).
 */
#[CoversClass(TurboSmsProvider::class)]
final class TurboSmsProviderTest extends TestCase
{
    public function testSuccessfulSendReturnsProviderMessageId(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'response_code' => 0,
            'response_status' => 'OK',
            'response_result' => [['phone' => '380671234567', 'response_code' => 0, 'message_id' => 'ts-42']],
        ], \JSON_THROW_ON_ERROR)));

        $provider = $this->provider($client);

        self::assertSame('ts-42', $provider->send('380671234567', 'Текст'));
        self::assertSame('turbosms', $provider->name());
    }

    public function testRequestCarriesTokenAndSender(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode([
                'response_code' => 0,
                'response_result' => [['message_id' => 'ts-1']],
            ], \JSON_THROW_ON_ERROR));
        });

        $this->provider($client)->send('380671234567', 'Текст');

        self::assertNotNull($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.turbosms.ua/message/send.json', $captured['url']);
        self::assertContains('Authorization: Bearer test-token', $captured['options']['headers']);
        self::assertStringContainsString('"sender":"Silpo"', (string) $captured['options']['body']);
    }

    public function testServerErrorIsRetryable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        try {
            $this->provider($client)->send('380671234567', 'Текст');
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertTrue($e->isRetryable());
            self::assertStringContainsString('HTTP 503', $e->getMessage());
        }
    }

    public function testTooManyRequestsIsRetryable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 429]));

        $this->expectException(TransportException::class);
        $this->provider($client)->send('380671234567', 'Текст');
    }

    public function testClientErrorIsPermanent(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        try {
            $this->provider($client)->send('380671234567', 'Текст');
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testProviderLevelErrorCodeIsPermanent(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'response_code' => 103,
            'response_status' => 'NOT_ENOUGH_MONEY',
        ], \JSON_THROW_ON_ERROR)));

        try {
            $this->provider($client)->send('380671234567', 'Текст');
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('NOT_ENOUGH_MONEY', $e->getMessage());
        }
    }

    public function testMissingTokenFailsFast(): void
    {
        $provider = new TurboSmsProvider(new MockHttpClient(), 'https://api.turbosms.ua/message/send.json', '', 'Silpo');

        try {
            $provider->send('380671234567', 'Текст');
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('TURBOSMS_TOKEN', $e->getMessage());
        }
    }

    public function testResponseWithoutMessageIdIsRetryable(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['response_code' => 0], \JSON_THROW_ON_ERROR)));

        try {
            $this->provider($client)->send('380671234567', 'Текст');
            self::fail('Очікувалася помилка транспорту.');
        } catch (TransportException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    private function provider(MockHttpClient $client): TurboSmsProvider
    {
        return new TurboSmsProvider($client, 'https://api.turbosms.ua/message/send.json', 'test-token', 'Silpo');
    }
}
