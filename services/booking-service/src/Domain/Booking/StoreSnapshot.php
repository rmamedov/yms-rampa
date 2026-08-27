<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Снапшот філії на момент бронювання (DATA-13). Перейменування магазину
 * не повинно змінювати вже надрукований маршрутний лист.
 */
final readonly class StoreSnapshot
{
    public function __construct(
        public string $externalId,
        public string $displayName,
        public string $city,
        public string $address,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'externalId' => $this->externalId,
            'displayName' => $this->displayName,
            'city' => $this->city,
            'address' => $this->address,
        ];
    }
}
