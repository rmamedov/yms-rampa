<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\RouteSheet\RouteSheet;
use App\Domain\RouteSheet\RouteSheetRepository;

/**
 * Маршрутні листи в памʼяті. Емулює unique {supplierId, date} —
 * один активний лист на пару (постачальник, дата).
 */
final class InMemoryRouteSheetRepository implements RouteSheetRepository
{
    /** @var array<string, RouteSheet> */
    private array $sheets = [];

    public function find(string $routeSheetId): ?RouteSheet
    {
        $sheet = $this->sheets[$routeSheetId] ?? null;

        return null === $sheet ? null : clone $sheet;
    }

    public function findBySupplierAndDate(string $supplierId, string $date): ?RouteSheet
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet->supplierId === $supplierId && $sheet->date === $date) {
                return clone $sheet;
            }
        }

        return null;
    }

    public function findByDriverAndDate(string $driverId, string $date): array
    {
        $result = [];

        foreach ($this->sheets as $sheet) {
            if ($sheet->date === $date && [] !== $sheet->bookingIdsForDriver($driverId)) {
                $result[] = clone $sheet;
            }
        }

        return $result;
    }

    public function save(RouteSheet $routeSheet): void
    {
        $this->sheets[$routeSheet->id] = clone $routeSheet;
    }

    public function clear(): void
    {
        $this->sheets = [];
    }
}
