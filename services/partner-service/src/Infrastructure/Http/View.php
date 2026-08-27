<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\PartnerUser\PartnerUser;
use App\Domain\Supplier\Supplier;
use App\Domain\Supplier\SupplierAccessSnapshot;
use App\Domain\Supplier\SupplierContact;
use App\Domain\Vehicle\Vehicle;

/**
 * Перетворення доменних агрегатів на JSON-подання API.
 *
 * DATA-01: назовні дати віддаються рядками ISO 8601 в UTC; у Europe/Kyiv
 * їх переводить фронтенд (SUP-UX-03).
 */
final class View
{
    /**
     * @return array<string, mixed>
     */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id(),
            'name' => $supplier->name(),
            'edrpou' => $supplier->edrpou(),
            'status' => $supplier->status()->value,
            'statusLabel' => $supplier->status()->label(),
            'storeAccess' => $supplier->storeAccess()->toArray(),
            'contacts' => array_map(
                static fn (SupplierContact $contact): array => $contact->toArray(),
                $supplier->contacts(),
            ),
            'suspendedAt' => self::date($supplier->suspendedAt()),
            'suspendReason' => $supplier->suspendReason(),
            'createdAt' => self::date($supplier->createdAt()),
            'updatedAt' => self::date($supplier->updatedAt()),
        ];
    }

    /**
     * Службове подання для booking-service (BOOK-02).
     *
     * Свідомо вужче за View::supplier(): між сервісами їдуть лише поля, від
     * яких залежить рішення про бронювання. `allowedStoreIds` порожній, коли
     * `allStores` = true — саме так це трактує SupplierInfo booking-service.
     *
     * @return array{supplierId: string, name: string, status: string, allStores: bool, allowedStoreIds: list<string>}
     */
    public static function supplierAccess(SupplierAccessSnapshot $snapshot): array
    {
        return [
            'supplierId' => $snapshot->supplierId,
            'name' => $snapshot->name,
            'status' => $snapshot->status->value,
            'allStores' => $snapshot->allStores,
            'allowedStoreIds' => $snapshot->allowedStoreIds,
        ];
    }

    /**
     * Готова відповідь на питання «чи може цей постачальник бронювати в цій
     * філії». Надмножина supplierAccess(): booking-service одним викликом
     * отримує і рішення, і сам знімок постачальника для журналу та UI.
     *
     * @return array<string, mixed>
     */
    public static function supplierStoreAccess(SupplierAccessSnapshot $snapshot, string $storeId): array
    {
        $reason = $snapshot->denialReason($storeId);

        return self::supplierAccess($snapshot) + [
            'storeId' => $storeId,
            'allowed' => null === $reason,
            // Причина заповнена лише за відмови: SUPPLIER_SUSPENDED або
            // SUPPLIER_STORE_NOT_ALLOWED.
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function vehicle(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id(),
            'supplierId' => $vehicle->supplierId(),
            'plateNumber' => $vehicle->plateNumber(),
            'brand' => $vehicle->brand(),
            'weightTons' => $vehicle->weightTons(),
            'active' => $vehicle->isActive(),
            'lastUsedAt' => self::date($vehicle->lastUsedAt()),
            'createdAt' => self::date($vehicle->createdAt()),
            'updatedAt' => self::date($vehicle->updatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function driver(PartnerUser $driver): array
    {
        return [
            'id' => $driver->id(),
            'accountId' => $driver->accountId(),
            'supplierId' => $driver->supplierId(),
            'phone' => $driver->phone(),
            'firstName' => $driver->firstName(),
            'lastName' => $driver->lastName(),
            'defaultVehicleId' => $driver->defaultVehicleId(),
            'active' => $driver->isActive(),
            'createdAt' => self::date($driver->createdAt()),
            'updatedAt' => self::date($driver->updatedAt()),
        ];
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->format(\DATE_ATOM);
    }
}
