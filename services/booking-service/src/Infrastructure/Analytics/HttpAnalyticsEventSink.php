<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Application\Outbox\AnalyticsEventSink;
use App\Application\Outbox\SinkReport;
use App\Domain\Exception\UpstreamUnavailableException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Доставка подій outbox у analytics-service поверх Symfony HttpClient.
 *
 * КОНТРАКТ СУСІДА (джерело істини — InternalEventIngestController
 * analytics-service):
 *
 *   POST {base}/internal/v1/analytics/events
 *        {"events":[{"eventId","name","occurredAt","payload"}, …]}
 *   200  {"received","applied","duplicate","ignored","orphan","failed":[…]}
 *   422  problem+json — тіло не є пакетом подій (наша помилка, не сусідова).
 *
 * Базовий URL — внутрішній шлюз nginx (ANALYTICS_SERVICE_BASE_URL, типово
 * http://127.0.0.1:8081): маршрут /internal/v1/analytics… він сам спрямовує
 * в analytics-service. Назовні шлюз не публікується.
 *
 * ТАЙМАУТ окремий і навмисно великий (30 с): на відміну від решти
 * міжсервісних клієнтів, цей викликається не з HTTP-запиту користувача, а з
 * фонової команди за розкладом. Пакет на 200 подій — це 200 upsert-ів у
 * read-моделі сусіда, і обривати їх через 2,5 с означало б вічно
 * перевідправляти те, що вже майже доїхало.
 *
 * НЕВДАЧА = ВИНЯТОК. Мовчазне «нічого не доставлено» тут неприпустиме:
 * релей позначає записи опублікованими лише після успішної доставки, тож
 * будь-яка проковтнута помилка означала б беззвучно втрачені події.
 */
final readonly class HttpAnalyticsEventSink implements AnalyticsEventSink
{
    public const float DEFAULT_TIMEOUT_SECONDS = 30.0;

    private const string PATH = '/internal/v1/analytics/events';

    private const string SERVICE = 'analytics-service';

    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl = 'http://127.0.0.1:8081',
        private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    public function deliver(array $events): SinkReport
    {
        if ([] === $events) {
            return new SinkReport();
        }

        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/').self::PATH, [
                'headers' => ['Accept' => 'application/json'],
                'json' => ['events' => $events],
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            // false — не кидати виняток на 4xx/5xx: їх ми тлумачимо самі.
            $body = $response->getContent(false);
        } catch (HttpClientException $error) {
            // Таймаут, обрив, DNS, шлюз не піднято — усе сюди.
            throw UpstreamUnavailableException::analyticsService($error->getMessage(), $error);
        }

        if ($status < 200 || $status >= 300) {
            throw UpstreamUnavailableException::analyticsService(\sprintf('HTTP %d', $status));
        }

        return self::report($body);
    }

    private static function report(string $body): SinkReport
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw UpstreamUnavailableException::badResponse(self::SERVICE, 'некоректний JSON', $error);
        }

        if (!\is_array($decoded)) {
            throw UpstreamUnavailableException::badResponse(self::SERVICE, 'тіло відповіді не є обʼєктом');
        }

        $failed = [];

        foreach (\is_array($decoded['failed'] ?? null) ? $decoded['failed'] : [] as $entry) {
            $eventId = \is_array($entry) ? ($entry['eventId'] ?? null) : null;
            $reason = \is_array($entry) ? ($entry['reason'] ?? null) : null;

            $failed[] = [
                'eventId' => \is_string($eventId) ? $eventId : null,
                'reason' => \is_string($reason) ? $reason : 'причину не вказано',
            ];
        }

        return new SinkReport(
            applied: self::counter($decoded, 'applied'),
            duplicate: self::counter($decoded, 'duplicate'),
            ignored: self::counter($decoded, 'ignored'),
            orphan: self::counter($decoded, 'orphan'),
            failed: $failed,
        );
    }

    /** @param array<array-key, mixed> $payload */
    private static function counter(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return \is_int($value) ? $value : 0;
    }
}
