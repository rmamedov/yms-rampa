<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Analytics;

use App\Domain\Exception\UpstreamUnavailableException;
use App\Infrastructure\Analytics\HttpAnalyticsEventSink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Транспорт релея outbox → analytics-service.
 *
 * Мережі тут немає: MockHttpClient, а тіла відповідей — фікстури РЕАЛЬНОГО
 * контракту сусіда (InternalEventIngestController analytics-service).
 */
#[CoversClass(HttpAnalyticsEventSink::class)]
final class HttpAnalyticsEventSinkTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:8081';
    private const INGEST_URL = self::BASE_URL.'/internal/v1/analytics/events';

    public function testPostsBatchToInternalRoute(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse($this->body(['applied' => 2]));
        });

        $report = $this->sink($client)->deliver([$this->envelope('ob-1'), $this->envelope('ob-2')]);

        self::assertSame('POST', $captured['method']);
        self::assertSame(self::INGEST_URL, $captured['url']);
        // Службовий маршрут, а не адмінський API аналітики.
        self::assertStringNotContainsString('/api/', $captured['url']);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured['options']['body'], true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame(['ob-1', 'ob-2'], array_column($body['events'], 'eventId'));
        self::assertSame(2, $report->applied);
    }

    /** Порожній пакет мережу не чіпає взагалі. */
    public function testEmptyBatchMakesNoRequest(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse($this->body([]));
        });

        $report = $this->sink($client)->deliver([]);

        self::assertSame(0, $calls);
        self::assertFalse($report->hasProblems());
    }

    public function testReadsAllCountersAndFailuresFromResponse(): void
    {
        $client = new MockHttpClient(new MockResponse($this->body([
            'applied' => 3,
            'duplicate' => 1,
            'ignored' => 2,
            'orphan' => 1,
            'failed' => [['eventId' => 'ob-9', 'reason' => 'Подія без поля city.']],
        ])));

        $report = $this->sink($client)->deliver([$this->envelope('ob-1')]);

        self::assertSame(3, $report->applied);
        self::assertSame(1, $report->duplicate);
        self::assertSame(2, $report->ignored);
        self::assertSame(1, $report->orphan);
        self::assertSame([['eventId' => 'ob-9', 'reason' => 'Подія без поля city.']], $report->failed);
        self::assertTrue($report->hasProblems());
    }

    public function testUnreachableNeighbourRaisesUpstreamException(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        try {
            $this->sink($client)->deliver([$this->envelope('ob-1')]);
            self::fail('Очікувався UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame('analytics-service', $error->service);
            self::assertSame(UpstreamUnavailableException::ERROR_CODE, $error->errorCode());
        }
    }

    public function testNonSuccessStatusRaisesUpstreamException(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $this->expectException(UpstreamUnavailableException::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->sink($client)->deliver([$this->envelope('ob-1')]);
    }

    public function testUnparsableBodyRaisesBadResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>ok</html>'));

        try {
            $this->sink($client)->deliver([$this->envelope('ob-1')]);
            self::fail('Очікувався UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(UpstreamUnavailableException::BAD_RESPONSE_CODE, $error->errorCode());
            self::assertSame(502, $error->httpStatus());
        }
    }

    /** Сусід відповів без лічильників — це не аварія, а нуль. */
    public function testMissingCountersDefaultToZero(): void
    {
        $client = new MockHttpClient(new MockResponse('{"received":1}'));

        $report = $this->sink($client)->deliver([$this->envelope('ob-1')]);

        self::assertSame(0, $report->applied);
        self::assertSame([], $report->failed);
    }

    private function sink(MockHttpClient $client): HttpAnalyticsEventSink
    {
        return new HttpAnalyticsEventSink($client, self::BASE_URL);
    }

    /** @return array<string, mixed> */
    private function envelope(string $eventId): array
    {
        return [
            'eventId' => $eventId,
            'name' => 'BookingArrived',
            'occurredAt' => '2026-08-27T06:00:00Z',
            'payload' => ['bookingId' => 'bk-1'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides): string
    {
        return json_encode($overrides + [
            'received' => 1,
            'applied' => 0,
            'duplicate' => 0,
            'ignored' => 0,
            'orphan' => 0,
            'failed' => [],
        ], \JSON_THROW_ON_ERROR);
    }
}
