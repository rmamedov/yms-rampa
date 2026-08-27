<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\RouteSheet\RouteSheet;
use App\Domain\RouteSheet\RouteSheetEntry;
use App\Domain\RouteSheet\RouteSheetRepository;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Query;

/**
 * Маршрутні листи в MongoDB (колекція `route_sheets`, розділ 10.3.2).
 */
final readonly class MongoRouteSheetRepository implements RouteSheetRepository
{
    public const string COLLECTION = 'route_sheets';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function find(string $routeSheetId): ?RouteSheet
    {
        return $this->query(['_id' => $routeSheetId], ['limit' => 1])[0] ?? null;
    }

    public function findBySupplierAndDate(string $supplierId, string $date): ?RouteSheet
    {
        return $this->query([
            'supplierId' => $supplierId,
            'date' => $date,
            'archivedAt' => null,
        ], ['limit' => 1])[0] ?? null;
    }

    public function findByDriverAndDate(string $driverId, string $date): array
    {
        return $this->query([
            'date' => $date,
            'archivedAt' => null,
            'entries.driverId' => $driverId,
        ]);
    }

    public function save(RouteSheet $routeSheet): void
    {
        $bulk = new BulkWrite();
        $bulk->update(
            ['_id' => $routeSheet->id],
            ['$set' => array_merge($routeSheet->toArray(), [
                'schemaVersion' => 1,
                'archivedAt' => null,
            ])],
            ['upsert' => true],
        );

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespace(self::COLLECTION),
            $bulk,
        );
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<RouteSheet>
     */
    private function query(array $filter, array $options = []): array
    {
        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespace(self::COLLECTION),
            new Query($filter, $options),
        );

        $sheets = [];

        foreach ($cursor as $document) {
            $document = (array) $document;
            $entries = [];

            foreach ((array) ($document['entries'] ?? []) as $entry) {
                $entry = (array) $entry;
                $entries[] = new RouteSheetEntry(
                    bookingId: (string) $entry['bookingId'],
                    driverId: isset($entry['driverId']) ? (string) $entry['driverId'] : null,
                    sortOrder: (int) ($entry['sortOrder'] ?? 0),
                );
            }

            $sheets[] = RouteSheet::reconstitute(
                id: (string) $document['_id'],
                supplierId: (string) $document['supplierId'],
                date: (string) $document['date'],
                entries: $entries,
                printVersion: (int) ($document['printVersion'] ?? 1),
            );
        }

        return $sheets;
    }
}
