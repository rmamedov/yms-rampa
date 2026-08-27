<?php

declare(strict_types=1);

namespace App\Domain\Store;

/**
 * Постачальник конфігурації магазину. Реальні дані живуть у store-service
 * і читаються по HTTP з кешем у Redis (TTL 60 с, інвалідація подією
 * StoreConfigChanged) — booking-service бачить лише цей контракт.
 *
 * @see \App\Infrastructure\Store\FixtureStoreConfigProvider  локальна заглушка
 * @see \App\Infrastructure\Store\HttpStoreConfigProvider     реальний клієнт
 */
interface StoreConfigProvider
{
    /**
     * @throws StoreNotFoundException якщо магазину немає або він не підключений до YMS
     */
    public function settingsFor(string $storeId): StoreSettings;
}
