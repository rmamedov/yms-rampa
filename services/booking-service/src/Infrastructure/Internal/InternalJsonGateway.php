<?php

declare(strict_types=1);

namespace App\Infrastructure\Internal;

use App\Domain\Exception\UpstreamUnavailableException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Спільна «трубопровідна» частина викликів до сусідніх мікросервісів:
 * GET на службовий маршрут /internal/v1/… внутрішнього шлюзу, таймаут,
 * розбір JSON, переклад будь-якої транспортної біди в доменний виняток
 * і памʼятний кеш у межах одного HTTP-запиту.
 *
 * Це НЕ сервіс контейнера: кожен клієнт-адаптер створює власний екземпляр у
 * своєму конструкторі. Так у контейнері не заводиться двох безіменних копій
 * одного класу з різними базовими URL, а тести підставляють MockHttpClient
 * прямо в конструктор адаптера.
 *
 * Службові маршрути не проходять через auth_request і не мають заголовків
 * ідентичності, тому клієнт нічого не підписує і нічого не проксює: шлюз
 * слухає лише 127.0.0.1 (див. infra/nginx-yms-internal.conf).
 */
final class InternalJsonGateway
{
    /**
     * Таймаут одного виклику до сусіда. Службові відповіді маленькі й
     * локальні, тому 2.5 с — це вже аварія, а не повільна мережа; довше
     * тримати користувача в очікуванні сенсу немає.
     */
    public const float DEFAULT_TIMEOUT_SECONDS = 2.5;

    /**
     * Кеш відповідей у межах життя обʼєкта, тобто одного HTTP-запиту
     * (php-fpm збирає контейнер наново на кожен запит).
     *
     * Ключ — шлях, значення — розібране тіло або null для 404. Саме тому
     * використовується array_key_exists, а не isset: «сусід відповів 404»
     * теж кешується і не смикає його вдруге.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        /** Імʼя сусіда для повідомлень і журналу: store-service, partner-service. */
        private readonly string $service,
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * @param string $path шлях службового маршруту, напр. /internal/v1/stores/{id}/settings
     *
     * @return array<string, mixed>|null null — сусід відповів 404 (сутності немає)
     *
     * @throws UpstreamUnavailableException сусід недоступний або відповів не за контрактом
     */
    public function getJson(string $path): ?array
    {
        if (\array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        return $this->cache[$path] = $this->fetch($path);
    }

    /** Екранування сегмента шляху: id приходять ззовні і не мають ламати маршрут. */
    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $path): ?array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                // timeout — простій зʼєднання, max_duration — жорстка стеля
                // на весь виклик; без другого повільний сусід міг би тримати
                // запит бронювання скільки завгодно.
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();

            // 404 — це відповідь по контракту (STORE_NOT_FOUND, STORE_NOT_CONFIGURED,
            // SUPPLIER_NOT_FOUND), а не збій: доменне тлумачення лишається виклику.
            if (404 === $status) {
                return null;
            }

            if ($status < 200 || $status >= 300) {
                throw $this->unavailable(\sprintf('HTTP %d', $status));
            }

            $body = $response->getContent(false);
        } catch (HttpClientException $error) {
            // Таймаут, обрив, DNS, недоступний шлюз — усе сюди.
            throw $this->unavailable($error->getMessage(), $error);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw UpstreamUnavailableException::badResponse($this->service, 'некоректний JSON', $error);
        }

        if (!\is_array($decoded)) {
            throw UpstreamUnavailableException::badResponse($this->service, 'тіло відповіді не є обʼєктом');
        }

        return $decoded;
    }

    private function unavailable(string $reason, ?HttpClientException $previous = null): UpstreamUnavailableException
    {
        return 'partner-service' === $this->service
            ? UpstreamUnavailableException::partnerService($reason, $previous)
            : UpstreamUnavailableException::storeService($reason, $previous);
    }
}
