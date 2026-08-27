<?php

declare(strict_types=1);

namespace App\Domain\Store;

/**
 * Магазин у переліку доступних користувачеві філій.
 *
 * Це НЕ StoreSettings: тут немає ані геометрії сітки, ані параметрів движка —
 * рівно ті поля, якими підписані перемикач філії і шапка контуру магазину.
 * Джерело — store-service; для booking-service це незмінний знімок.
 */
final readonly class StoreBrief
{
    public function __construct(
        public string $storeId,
        public string $externalId,
        public string $displayName,
        public string $city,
        public string $address,
        /** Статус філії в YMS: у переліку буває лише `active` (див. StoreDirectory). */
        public string $ymsStatus = 'active',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'storeId' => $this->storeId,
            'externalId' => $this->externalId,
            'displayName' => $this->displayName,
            'city' => $this->city,
            'address' => $this->address,
            'ymsStatus' => $this->ymsStatus,
        ];
    }
}
