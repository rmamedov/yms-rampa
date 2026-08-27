<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Application\Outbox\AnalyticsEventSink;
use App\Application\Outbox\EventOutcome;
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
 *   200  {"received","applied","duplicate","ignored","orphan","rejected",
 *         "results":[{"index","eventId","outcome","reason"}, …]}
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

        return self::report($body, \count($events));
    }

    /**
     * Присуд споживача за КОЖНОЮ подією.
     *
     * Неповний або відсутній `results` — це порушення контракту, а не привід
     * «здогадатися»: релей прибирає з черги лише те, що сусід прийняв, тож без
     * поіменного звіту він або втратив би відхилені події, або вічно
     * перевідправляв би застосовані. Тому тут виняток, і жоден запис не
     * змінює стану.
     *
     * @param int $expected скільки подій було надіслано
     */
    private static function report(string $body, int $expected): SinkReport
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

        $entries = $decoded['results'] ?? null;

        if (!\is_array($entries)) {
            throw UpstreamUnavailableException::badResponse(
                self::SERVICE,
                'у відповіді немає переліку results із присудом за кожною подією',
            );
        }

        $rows = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                throw UpstreamUnavailableException::badResponse(self::SERVICE, 'елемент results не є обʼєктом');
            }

            $index = $entry['index'] ?? null;
            $outcome = \is_string($entry['outcome'] ?? null) ? EventOutcome::tryFrom($entry['outcome']) : null;

            if (!\is_int($index) || null === $outcome) {
                throw UpstreamUnavailableException::badResponse(
                    self::SERVICE,
                    'елемент results без коректних полів index і outcome',
                );
            }

            $reason = $entry['reason'] ?? null;
            $rows[] = [
                'index' => $index,
                'outcome' => $outcome,
                'reason' => \is_string($reason) && '' !== $reason ? $reason : null,
            ];
        }

        if (\count($rows) !== $expected) {
            throw UpstreamUnavailableException::badResponse(self::SERVICE, \sprintf(
                'надіслано подій %d, а присудів у results %d',
                $expected,
                \count($rows),
            ));
        }

        return SinkReport::fromRows($rows);
    }
}
