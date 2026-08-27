<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Shared\ConflictException;
use App\Domain\Vehicle\Vehicle;
use App\Domain\Vehicle\VehicleRepository;

/**
 * Сховище автопарку в MongoDB, колекція `partners.vehicles` (розділ 10.4).
 *
 * DATA-18: фінальна гарантія унікальності — compound unique index
 * `{supplierId:1, plateNumber:1, archivedAt:1}`. Глобального unique-індексу
 * на `plateNumber` НЕМАЄ і бути не повинно (SUP-VEH-02).
 * Порушення індексу (код 11000) перекладається в доменний ConflictException.
 */
final readonly class MongoVehicleRepository implements VehicleRepository
{
    public const COLLECTION = 'vehicles';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function save(Vehicle $vehicle): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => $vehicle->id()],
            ['$set' => $this->toDocument($vehicle)],
            ['upsert' => true],
        );

        try {
            $this->connection->manager()->executeBulkWrite(
                $this->connection->namespaceFor(self::COLLECTION),
                $bulk,
            );
        } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
            if (11000 === $e->getCode()) {
                throw new ConflictException(
                    'Авто з таким номером уже є у вашому довіднику.',
                    'VEHICLE_PLATE_DUPLICATE',
                );
            }

            throw $e;
        }
    }

    public function findById(string $id): ?Vehicle
    {
        return $this->findOne(['_id' => $id]);
    }

    public function findBySupplierAndPlate(string $supplierId, string $plateNumber): ?Vehicle
    {
        // Фільтр обмежений постачальником — це і є правило SUP-VEH-02.
        return $this->findOne([
            'supplierId' => $supplierId,
            'plateNumber' => $plateNumber,
            'archivedAt' => null,
        ]);
    }

    public function listBySupplier(string $supplierId, bool $includeInactive = false): array
    {
        $filter = ['supplierId' => $supplierId, 'archivedAt' => null];

        if (!$includeInactive) {
            $filter['active'] = true;
        }

        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespaceFor(self::COLLECTION),
            new \MongoDB\Driver\Query($filter, ['sort' => ['lastUsedAt' => -1, 'plateNumber' => 1]]),
        );
        $cursor->setTypeMap(MongoCodec::TYPE_MAP);

        $result = [];

        foreach ($cursor as $document) {
            /** @var array<string, mixed> $document */
            $result[] = $this->hydrate($document);
        }

        return $result;
    }

    public function remove(string $id): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->delete(['_id' => $id], ['limit' => 1]);

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespaceFor(self::COLLECTION),
            $bulk,
        );
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function findOne(array $filter): ?Vehicle
    {
        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespaceFor(self::COLLECTION),
            new \MongoDB\Driver\Query($filter, ['limit' => 1]),
        );
        $cursor->setTypeMap(MongoCodec::TYPE_MAP);

        $documents = $cursor->toArray();

        if ([] === $documents) {
            return null;
        }

        /** @var array<string, mixed> $document */
        $document = $documents[0];

        return $this->hydrate($document);
    }

    /**
     * @return array<string, mixed>
     */
    private function toDocument(Vehicle $vehicle): array
    {
        return [
            'supplierId' => $vehicle->supplierId(),
            'plateNumber' => $vehicle->plateNumber(),
            'brand' => $vehicle->brand(),
            'weightTons' => $vehicle->weightTons(),
            'active' => $vehicle->isActive(),
            'lastUsedAt' => MongoCodec::toBson($vehicle->lastUsedAt()),
            'archivedAt' => MongoCodec::toBson($vehicle->archivedAt()),
            'createdAt' => MongoCodec::toBson($vehicle->createdAt()),
            'updatedAt' => MongoCodec::toBson($vehicle->updatedAt()),
            'schemaVersion' => $vehicle->schemaVersion(),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function hydrate(array $document): Vehicle
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return Vehicle::reconstitute(
            id: (string) $document['_id'],
            supplierId: (string) $document['supplierId'],
            plateNumber: (string) $document['plateNumber'],
            weightTons: (float) $document['weightTons'],
            brand: isset($document['brand']) ? (string) $document['brand'] : null,
            active: (bool) ($document['active'] ?? true),
            createdAt: MongoCodec::toPhpRequired($document['createdAt'] ?? null, $now),
            updatedAt: MongoCodec::toPhpRequired($document['updatedAt'] ?? null, $now),
            lastUsedAt: MongoCodec::toPhp($document['lastUsedAt'] ?? null),
            archivedAt: MongoCodec::toPhp($document['archivedAt'] ?? null),
            schemaVersion: (int) ($document['schemaVersion'] ?? 1),
        );
    }
}
