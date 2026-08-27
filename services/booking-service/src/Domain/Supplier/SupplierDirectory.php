<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

use App\Domain\Booking\Exception\SupplierNotAllowedException;

/**
 * Довідник постачальників (partner-service). BOOK-02: постачальник повинен
 * бути active і мати доступ до конкретної філії.
 */
interface SupplierDirectory
{
    public function find(string $supplierId): ?SupplierInfo;

    /**
     * @throws SupplierNotAllowedException якщо постачальник неактивний або без доступу
     */
    public function assertMayBookAt(string $supplierId, string $storeId): SupplierInfo;
}
