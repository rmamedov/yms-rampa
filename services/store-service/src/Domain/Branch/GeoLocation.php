<?php

declare(strict_types=1);

namespace App\Domain\Branch;

use App\Domain\Shared\ValidationException;

/**
 * Координати філії з MCP. Зберігаються як GeoJSON Point [lng, lat] (10.2.1).
 */
final readonly class GeoLocation
{
    /** Обмежувальна рамка України для відсіву тестових філій (fixtures/README.md). */
    public const float UKRAINE_MIN_LAT = 44.0;
    public const float UKRAINE_MAX_LAT = 52.5;
    public const float UKRAINE_MIN_LNG = 22.0;
    public const float UKRAINE_MAX_LNG = 40.5;

    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw ValidationException::field('latitude', 'Широта має бути в межах -90..90');
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw ValidationException::field('longitude', 'Довгота має бути в межах -180..180');
        }
    }

    /** Чи потрапляють координати в bbox України. */
    public function isWithinUkraine(): bool
    {
        return $this->latitude >= self::UKRAINE_MIN_LAT
            && $this->latitude <= self::UKRAINE_MAX_LAT
            && $this->longitude >= self::UKRAINE_MIN_LNG
            && $this->longitude <= self::UKRAINE_MAX_LNG;
    }

    /** @return array{type: string, coordinates: array{float, float}} */
    public function toGeoJson(): array
    {
        return ['type' => 'Point', 'coordinates' => [$this->longitude, $this->latitude]];
    }

    public function equals(?self $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }

        return abs($this->latitude - $other->latitude) < 0.0000001
            && abs($this->longitude - $other->longitude) < 0.0000001;
    }
}
