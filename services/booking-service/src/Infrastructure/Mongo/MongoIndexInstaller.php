<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Booking\BookingStatus;
use MongoDB\Driver\Command;

/**
 * Створення індексів БД `bookings` (розділ 10.3).
 *
 * Найважливіший — DATA-12: unique partial index
 * {storeId, rampId, slotStart} з partialFilterExpression на активні статуси.
 * Саме він є фінальною гарантією відсутності подвійного бронювання рампи
 * (BOOK-07/BOOK-08).
 */
final readonly class MongoIndexInstaller
{
    public function __construct(private MongoConnection $connection)
    {
    }

    /**
     * @return list<string> назви створених індексів
     */
    public function install(): array
    {
        $created = [];

        foreach ($this->definitions() as $collection => $indexes) {
            $this->connection->manager()->executeCommand(
                $this->connection->database(),
                new Command([
                    'createIndexes' => $collection,
                    'indexes' => $indexes,
                ]),
            );

            foreach ($indexes as $index) {
                $created[] = $collection.'.'.$index['name'];
            }
        }

        return $created;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function definitions(): array
    {
        return [
            MongoBookingRepository::COLLECTION => [
                [
                    // DATA-12 (критичний): подвійне бронювання рампи неможливе.
                    'name' => 'uniq_active_slot_key',
                    'key' => ['storeId' => 1, 'rampId' => 1, 'slotStart' => 1],
                    'unique' => true,
                    'partialFilterExpression' => ['status' => ['$in' => BookingStatus::activeValues()]],
                ],
                ['name' => 'supplier_slot', 'key' => ['supplierId' => 1, 'slotStart' => -1]],
                ['name' => 'store_day_board', 'key' => ['storeId' => 1, 'slotStart' => 1, 'status' => 1]],
                ['name' => 'driver_route', 'key' => ['driverId' => 1, 'slotStart' => 1]],
                // NOSH-01: вибірка кандидатів на авто-no_show.
                ['name' => 'no_show_sweep', 'key' => ['status' => 1, 'slotEnd' => 1]],
                ['name' => 'walk_in_analytics', 'key' => ['type' => 1, 'slotStart' => -1]],
            ],
            MongoRouteSheetRepository::COLLECTION => [
                [
                    'name' => 'uniq_supplier_date',
                    'key' => ['supplierId' => 1, 'date' => 1, 'archivedAt' => 1],
                    'unique' => true,
                ],
                ['name' => 'driver_day', 'key' => ['entries.driverId' => 1, 'date' => 1]],
            ],
            MongoOutboxStore::COLLECTION => [
                [
                    'name' => 'relay_queue',
                    'key' => ['publishedAt' => 1, 'occurredAt' => 1],
                    'partialFilterExpression' => ['publishedAt' => null],
                ],
                [
                    'name' => 'published_ttl',
                    'key' => ['publishedAt' => 1],
                    'expireAfterSeconds' => 2592000,
                ],
            ],
        ];
    }
}
