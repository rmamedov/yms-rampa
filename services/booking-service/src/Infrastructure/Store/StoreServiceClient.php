<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

/**
 * Мінімальний контракт HTTP-клієнта до store-service. Винесений в інтерфейс,
 * щоб HttpStoreConfigProvider можна було тестувати без мережі, а транспорт
 * (curl, Symfony HttpClient) міняти незалежно.
 */
interface StoreServiceClient
{
    /**
     * @return array<string, mixed>|null сирий JSON конфігурації магазину
     *                                   або null, якщо магазину немає
     */
    public function fetchStore(string $storeId): ?array;
}
