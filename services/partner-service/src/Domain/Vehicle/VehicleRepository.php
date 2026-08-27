<?php

declare(strict_types=1);

namespace App\Domain\Vehicle;

/**
 * Порт сховища автопарку. Реалізації: MongoDB (прод) та InMemory (dev/тести).
 *
 * DATA-18: унікальність гарантується парою `{supplierId, plateNumber}`
 * серед неархівованих документів, а не глобально по колекції.
 */
interface VehicleRepository
{
    public function save(Vehicle $vehicle): void;

    public function findById(string $id): ?Vehicle;

    /**
     * Пошук авто за нормалізованим номером У МЕЖАХ одного постачальника.
     * Саме цей метод реалізує правило SUP-VEH-02.
     */
    public function findBySupplierAndPlate(string $supplierId, string $plateNumber): ?Vehicle;

    /**
     * @return list<Vehicle> відсортовано за lastUsedAt DESC, потім за номером
     */
    public function listBySupplier(string $supplierId, bool $includeInactive = false): array;

    /**
     * Фізичне видалення (використовується лише міграціями/тестами).
     * Бізнес-видалення — через Vehicle::archive() і save().
     */
    public function remove(string $id): void;
}
