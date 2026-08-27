<?php

declare(strict_types=1);

namespace App\Domain\RouteSheet;

/**
 * Сховище маршрутних листів. Один активний лист на пару (постачальник, дата) —
 * unique {supplierId:1, date:1, archivedAt:1} (розділ 10.3.2).
 */
interface RouteSheetRepository
{
    public function find(string $routeSheetId): ?RouteSheet;

    public function findBySupplierAndDate(string $supplierId, string $date): ?RouteSheet;

    /**
     * RSHT-04: листи, у яких водію призначено хоча б одне бронювання дати.
     *
     * @return list<RouteSheet>
     */
    public function findByDriverAndDate(string $driverId, string $date): array;

    public function save(RouteSheet $routeSheet): void;
}
