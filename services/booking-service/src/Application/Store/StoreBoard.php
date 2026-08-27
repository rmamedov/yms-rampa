<?php

declare(strict_types=1);

namespace App\Application\Store;

use App\Domain\Booking\Booking;
use App\Domain\Driver\DriverInfo;
use DateTimeImmutable;

/**
 * Дошка прибуттів магазину на одну локальну добу.
 *
 * `now` — серверний час, а не клієнтський: на ньому тримаються таймери
 * очікування і правила доступності дій у store-web (GRID-05).
 */
final readonly class StoreBoard
{
    /**
     * @param list<Booking>             $bookings усі бронювання доби, включно з
     *                                            завершеними і скасованими: дошка
     *                                            магазину показує день цілком
     * @param array<string, DriverInfo> $drivers  профілі призначених водіїв,
     *                                            ключ — driverId
     */
    public function __construct(
        public string $storeId,
        public string $date,
        public array $bookings,
        public array $drivers,
        public DateTimeImmutable $now,
    ) {
    }

    public function driverOf(Booking $booking): ?DriverInfo
    {
        $driverId = $booking->driverId();

        return null === $driverId ? null : ($this->drivers[$driverId] ?? null);
    }
}
