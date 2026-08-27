<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use RuntimeException;

/**
 * Клієнт store-service на ext-curl, без додаткових залежностей.
 * Кешування (Redis, TTL 60 с, інвалідація подією StoreConfigChanged)
 * реалізується декоратором навколо цього класу.
 */
final readonly class CurlStoreServiceClient implements StoreServiceClient
{
    public function __construct(
        private string $baseUrl,
        private int $timeoutSeconds = 3,
    ) {
    }

    public function fetchStore(string $storeId): ?array
    {
        $url = rtrim($this->baseUrl, '/').'/api/internal/v1/stores/'.rawurlencode($storeId).'/config';
        $handle = curl_init($url);

        if (false === $handle) {
            throw new RuntimeException('Не вдалося ініціалізувати HTTP-клієнт до store-service');
        }

        curl_setopt_array($handle, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $this->timeoutSeconds,
            \CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (404 === $status) {
            return null;
        }

        if (!\is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException(\sprintf('store-service повернув статус %d для магазину %s', $status, $storeId));
        }

        $decoded = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : null;
    }
}
