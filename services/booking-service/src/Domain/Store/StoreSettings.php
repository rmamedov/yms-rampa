<?php

declare(strict_types=1);

namespace App\Domain\Store;

use App\Domain\Booking\StoreSnapshot;
use App\Domain\Slot\StoreConfig;

/**
 * Повний знімок магазину, потрібний booking-service для одного запиту:
 * геометрія сітки (StoreConfig), параметри движка (StorePolicy),
 * снапшот філії для документа бронювання і ознака ymsStatus.
 */
final readonly class StoreSettings
{
    public function __construct(
        public StoreConfig $config,
        public StorePolicy $policy,
        public StoreSnapshot $snapshot,
        /** GRID-01, крок 2: якщо магазин не active — для постачальника це 404. */
        public bool $ymsActive = true,
    ) {
    }

    public function storeId(): string
    {
        return $this->config->storeId;
    }
}
