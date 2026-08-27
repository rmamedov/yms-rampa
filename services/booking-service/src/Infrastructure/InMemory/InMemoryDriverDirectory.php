<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Driver\DriverDirectory;
use App\Domain\Driver\DriverInfo;

/**
 * Довідник водіїв у памʼяті. У проді замінюється HTTP-клієнтом до
 * partner-service; контракт «невідомий профіль просто відсутній» однаковий.
 */
final class InMemoryDriverDirectory implements DriverDirectory
{
    /** @var array<string, DriverInfo> */
    private array $drivers = [];

    /**
     * @param list<DriverInfo> $drivers
     */
    public function __construct(array $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->add($driver);
        }
    }

    public function add(DriverInfo $driver): void
    {
        $this->drivers[$driver->driverId] = $driver;
    }

    public function findMany(array $driverIds): array
    {
        $found = [];

        foreach ($driverIds as $driverId) {
            if (isset($this->drivers[$driverId])) {
                $found[$driverId] = $this->drivers[$driverId];
            }
        }

        return $found;
    }
}
