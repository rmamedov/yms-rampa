<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Domain\Store\StoreBrief;
use App\Domain\Store\StoreDirectory;

/**
 * Перелік магазинів поверх store-service (GET /internal/v1/stores).
 *
 * Контракт сусіда:
 *   200 {"items":[{"storeId","ymsStatus","snapshot":{externalId,displayName,
 *        city,address}}],"total":N,"page":P,"perPage":PP,"pages":K}
 *
 * ПАГІНАЦІЯ. Сусід віддає сторінку, а домену потрібен ПОВНИЙ перелік, тому
 * клієнт гортає всі сторінки до `pages`. Зупинитися на першій означало б
 * показати частину мережі як усю мережу — той самий дефект, що вже ловили в
 * інших довідниках проєкту. Стеля обходу існує, але вона захисна: коли б
 * сусід почав віддавати суперечливі `pages`, цикл має завершитися.
 */
final readonly class HttpStoreDirectory implements StoreDirectory
{
    /** Розмір сторінки; більший store-service не приймає (ALLOWED_PER_PAGE). */
    private const int PER_PAGE = 100;

    /** Захист від нескінченного обходу: 100 сторінок × 100 = 10 000 філій. */
    private const int MAX_PAGES = 100;

    public function __construct(private StoreServiceClient $client)
    {
    }

    public function listStores(?array $storeIds = null): array
    {
        $stores = [];

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $payload = $this->client->fetchStoreList($storeIds, $page, self::PER_PAGE);

            // 404 службового маршруту — сусід є, але переліку не віддає:
            // краще порожній перемикач, ніж 500 на весь екран.
            if (null === $payload) {
                break;
            }

            foreach ((array) ($payload['items'] ?? []) as $item) {
                $stores[] = self::toBrief((array) $item);
            }

            if ($page >= (int) ($payload['pages'] ?? 1)) {
                break;
            }
        }

        return $stores;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function toBrief(array $item): StoreBrief
    {
        $storeId = (string) ($item['storeId'] ?? '');
        $snapshot = (array) ($item['snapshot'] ?? []);

        return new StoreBrief(
            storeId: $storeId,
            externalId: (string) ($snapshot['externalId'] ?? $storeId),
            displayName: (string) ($snapshot['displayName'] ?? ''),
            city: (string) ($snapshot['city'] ?? ''),
            address: (string) ($snapshot['address'] ?? ''),
            ymsStatus: (string) ($item['ymsStatus'] ?? 'active'),
        );
    }
}
