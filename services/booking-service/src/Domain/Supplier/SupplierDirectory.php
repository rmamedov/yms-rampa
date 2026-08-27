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

    /**
     * ПОВНИЙ перелік активних постачальників, яким дозволена ця філія —
     * довідник для форми позапланового прибуття (WALK-01).
     *
     * «Повний» тут — вимога, а не побажання: реалізація поверх пагінованого
     * сусіда зобовʼязана пройти ВСІ сторінки. Обірвана на першій вибірка дала б
     * приймальнику список, у якому потрібного постачальника просто немає, —
     * і він завів би прибуття «поза системою» замість справжнього контрагента.
     *
     * @return list<SupplierInfo> відсортований за назвою
     */
    public function listForStore(string $storeId): array;
}
