<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Domain\Exception\UpstreamUnavailableException;

/**
 * Мінімальний контракт HTTP-клієнта до store-service. Винесений в інтерфейс,
 * щоб HttpStoreConfigProvider і HttpSlotOverlayProvider можна було тестувати
 * без мережі, а транспорт міняти незалежно.
 *
 * Обидва споживачі читають ОДНЕ І ТЕ САМЕ тіло відповіді
 * GET /internal/v1/stores/{storeId}/settings: конфігурація сітки і накладання
 * (резерви, блокування) приходять разом. Тому реалізація зобовʼязана кешувати
 * відповідь у межах запиту — інакше побудова однієї сітки дала б три виклики
 * до сусіда замість одного.
 */
interface StoreServiceClient
{
    /**
     * @return array<string, mixed>|null сире тіло /internal/v1/stores/{id}/settings
     *                                   або null, якщо store-service відповів 404
     *
     * @throws UpstreamUnavailableException store-service недоступний або відповів не за контрактом
     */
    public function fetchStore(string $storeId): ?array;

    /**
     * Сторінка службового переліку магазинів
     * (GET /internal/v1/stores?storeIds=…&page=…&perPage=…).
     *
     * @param list<string>|null $storeIds звуження скоупом; null — без звуження
     *
     * @return array<string, mixed>|null null — сусід відповів 404
     *
     * @throws UpstreamUnavailableException store-service недоступний або відповів не за контрактом
     */
    public function fetchStoreList(?array $storeIds, int $page, int $perPage): ?array;
}
