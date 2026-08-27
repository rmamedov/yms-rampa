<?php

declare(strict_types=1);

namespace App\Domain\Store;

/**
 * Координати філії (DRV-21). Приходять зі store-service у блоці `snapshot`
 * відповіді /internal/v1/stores/{id}/settings і потрібні рівно для одного —
 * щоб навігатор водія отримав точку на карті, а не пошуковий рядок.
 *
 * Координати НЕ вморожуються в документ бронювання: адреса філії у снапшоті
 * лишається сталою для друкованої форми (DATA-13), а маршрут завжди має вести
 * на поточне розташування магазину.
 */
final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }

    /**
     * Розбір пари полів довільного походження. Порожні, нечислові або
     * позамежні значення дають null — краще жодних координат, ніж маршрут
     * у Гвінейську затоку.
     */
    public static function tryFrom(mixed $latitude, mixed $longitude): ?self
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return new self($lat, $lng);
    }
}
