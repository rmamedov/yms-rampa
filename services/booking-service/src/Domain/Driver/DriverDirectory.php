<?php

declare(strict_types=1);

namespace App\Domain\Driver;

/**
 * Довідник профілів водіїв (partner-service).
 *
 * Потрібен лише для ЧИТАННЯ: бронювання зберігає ідентифікатор профілю, а
 * картці прибуття магазину потрібні ПІБ і телефон — приймальник дзвонить
 * водієві, а не UUID. Жодне доменне правило від цього довідника не залежить:
 * якщо сусід не відповість, дошка має показатися без імені, а не впасти.
 */
interface DriverDirectory
{
    /**
     * @param list<string> $driverIds ідентифікатори ПРОФІЛІВ водіїв
     *
     * @return array<string, DriverInfo> ключ — driverId; невідомі профілі
     *                                   у результаті просто відсутні
     */
    public function findMany(array $driverIds): array;
}
