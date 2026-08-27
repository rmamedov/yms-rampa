<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Infrastructure\Internal\InternalJsonGateway;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клієнт store-service поверх Symfony HttpClient.
 *
 * Контракт сусіда (перевірений на його ж тестах):
 *   GET {base}/internal/v1/stores/{storeId}/settings
 *   200 application/json — конфігурація магазину разом із накладаннями;
 *   404 application/problem+json з code = STORE_NOT_FOUND | STORE_NOT_CONFIGURED
 *       (філії немає, вона не active або чинної конфігурації немає).
 *
 * Обидва 404 для booking-service означають одне й те саме — «магазину для
 * бронювання не існує», тому клієнт не розрізняє їх і віддає null.
 *
 * Базовий URL — внутрішній шлюз nginx (STORE_SERVICE_BASE_URL,
 * типово http://127.0.0.1:8081): маршрут /internal/v1/stores… він сам
 * спрямовує у store-service.
 */
final class HttpStoreServiceClient implements StoreServiceClient
{
    private readonly InternalJsonGateway $gateway;

    public function __construct(
        HttpClientInterface $http,
        string $baseUrl,
        float $timeoutSeconds = InternalJsonGateway::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->gateway = new InternalJsonGateway($http, 'store-service', $baseUrl, $timeoutSeconds);
    }

    public function fetchStore(string $storeId): ?array
    {
        // Кеш живе в шлюзі й ключується шляхом, тож повторний виклик за тим
        // самим магазином у межах запиту мережу вже не чіпає.
        return $this->gateway->getJson(
            '/internal/v1/stores/'.InternalJsonGateway::segment($storeId).'/settings'
        );
    }
}
