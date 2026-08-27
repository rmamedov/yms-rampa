<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Shared\ConflictException;
use App\Domain\Vehicle\Vehicle;
use App\Domain\Vehicle\VehicleRepository;

/**
 * Сховище автопарку в пам'яті.
 *
 * Свідомо повторює поведінку unique-індексу DATA-18
 * `{supplierId:1, plateNumber:1, archivedAt:1}`: дублікат у межах
 * постачальника відхиляється навіть при обході доменної перевірки,
 * а однаковий номер у різних постачальників зберігається без помилки.
 */
final class InMemoryVehicleRepository implements VehicleRepository
{
    /** @var array<string, Vehicle> */
    private array $items = [];

    public function save(Vehicle $vehicle): void
    {
        if (null === $vehicle->archivedAt()) {
            $clash = $this->findBySupplierAndPlate($vehicle->supplierId(), $vehicle->plateNumber());

            if (null !== $clash && $clash->id() !== $vehicle->id()) {
                throw new ConflictException(
                    'Авто з таким номером уже є у вашому довіднику.',
                    'VEHICLE_PLATE_DUPLICATE',
                );
            }
        }

        $this->items[$vehicle->id()] = $vehicle;
    }

    public function findById(string $id): ?Vehicle
    {
        return $this->items[$id] ?? null;
    }

    public function findBySupplierAndPlate(string $supplierId, string $plateNumber): ?Vehicle
    {
        foreach ($this->items as $vehicle) {
            if (null !== $vehicle->archivedAt()) {
                continue;
            }

            if ($vehicle->supplierId() === $supplierId && $vehicle->plateNumber() === $plateNumber) {
                return $vehicle;
            }
        }

        return null;
    }

    public function listBySupplier(string $supplierId, bool $includeInactive = false): array
    {
        $found = [];

        foreach ($this->items as $vehicle) {
            if ($vehicle->supplierId() !== $supplierId || null !== $vehicle->archivedAt()) {
                continue;
            }

            if (!$includeInactive && !$vehicle->isActive()) {
                continue;
            }

            $found[] = $vehicle;
        }

        // «Останні авто» вгорі (індекс {supplierId:1, lastUsedAt:-1}), далі за номером.
        usort($found, static function (Vehicle $a, Vehicle $b): int {
            $aUsed = $a->lastUsedAt()?->getTimestamp() ?? 0;
            $bUsed = $b->lastUsedAt()?->getTimestamp() ?? 0;

            return $bUsed <=> $aUsed ?: strcmp($a->plateNumber(), $b->plateNumber());
        });

        return array_values($found);
    }

    public function remove(string $id): void
    {
        unset($this->items[$id]);
    }
}
