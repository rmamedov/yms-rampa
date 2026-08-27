<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Analytics;

use App\Application\Outbox\EventOutcome;
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

            return new MockResponse($this->body(['applied', 'applied']));
        });

        $report = $this->sink($client)->deliver([$this->envelope('ob-1'), $this->envelope('ob-2')]);

        self::assertSame('POST', $captured['method']);
        self::assertSame(self::INGEST_URL, $captured['url']);
        // Службовий маршрут, а не адмінський API аналітики.
        self::assertStringNotContainsString('/api/', $captured['url']);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured['options']['body'], true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame(['ob-1', 'ob-2'], array_column($body['events'], 'eventId'));
        self::assertSame(2, $report->count(EventOutcome::Applied));
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

    /** Присуд читається для КОЖНОЇ події і зіставляється за позицією в пакеті. */
    public function testReadsPerEventOutcomes(): void
    {
        $client = new MockHttpClient(new MockResponse($this->body(
            ['applied', 'duplicate', 'ignored', 'orphan', 'rejected'],
            [4 => 'Подія без поля city.'],
        )));

        $report = $this->sink($client)->deliver([
            $this->envelope('ob-1'), $this->envelope('ob-2'), $this->envelope('ob-3'),
            $this->envelope('ob-4'), $this->envelope('ob-5'),
        ]);

        self::assertSame(EventOutcome::Applied, $report->outcomeAt(0));
        self::assertSame(EventOutcome::Duplicate, $report->outcomeAt(1));
        self::assertSame(EventOutcome::Ignored, $report->outcomeAt(2));
        self::assertSame(EventOutcome::Orphan, $report->outcomeAt(3));
        self::assertSame(EventOutcome::Rejected, $report->outcomeAt(4));
        self::assertSame('Подія без поля city.', $report->reasonAt(4));
        self::assertTrue($report->hasProblems());
        self::assertCount(2, $report->undelivered());
    }

    /**
     * Відповідь без поіменного звіту — порушення контракту, а не привід
     * «здогадатися»: без нього релей не знає, що можна прибрати з черги.
     */
    public function testResponseWithoutResultsIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('{"received":1,"applied":1}'));

        try {
            $this->sink($client)->deliver([$this->envelope('ob-1')]);
            self::fail('Очікувався UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(UpstreamUnavailableException::BAD_RESPONSE_CODE, $error->errorCode());
            self::assertStringContainsString('results', $error->getMessage());
        }
    }

    /** Присудів менше, ніж подій, — теж порушення: чиясь доля лишилася б невідомою. */
    public function testIncompleteResultsAreRejected(): void
    {
        $client = new MockHttpClient(new MockResponse($this->body(['applied'])));

        $this->expectException(UpstreamUnavailableException::class);
        $this->expectExceptionMessage('надіслано подій 2, а присудів у results 1');

        $this->sink($client)->deliver([$this->envelope('ob-1'), $this->envelope('ob-2')]);
    }

    public function testUnknownOutcomeIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"results":[{"index":0,"eventId":"ob-1","outcome":"хтозна","reason":null}]}',
        ));

        $this->expectException(UpstreamUnavailableException::class);
        $this->expectExceptionMessage('index і outcome');

        $this->sink($client)->deliver([$this->envelope('ob-1')]);
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

    /**
     * Тіло відповіді за контрактом сусіда: присуд на кожну надіслану подію.
     *
     * @param list<string>        $outcomes присуди за позиціями
     * @param array<int, string>  $reasons  пояснення, ключ — та сама позиція
     */
    private function body(array $outcomes, array $reasons = []): string
    {
        $results = [];

        foreach ($outcomes as $index => $outcome) {
            $results[] = [
                'index' => $index,
                'eventId' => 'ob-'.($index + 1),
                'outcome' => $outcome,
                'reason' => $reasons[$index] ?? null,
            ];
        }

        return json_encode([
            'received' => \count($outcomes),
            'results' => $results,
        ], \JSON_THROW_ON_ERROR);
    }
}
