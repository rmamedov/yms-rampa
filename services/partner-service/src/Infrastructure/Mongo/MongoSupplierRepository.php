<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Supplier\StoreAccess;
use App\Domain\Supplier\Supplier;
use App\Domain\Supplier\SupplierContact;
use App\Domain\Supplier\SupplierRepository;
use App\Domain\Supplier\SupplierStatus;

/**
 * Сховище постачальників у MongoDB, колекція `partners.suppliers` (розділ 10.4).
 *
 * Індекси створюються міграцією/командою `app:partner:ensure-indexes` (DATA-28):
 *  - unique partial `{edrpou:1}` де edrpou≠null;
 *  - `{name:1}` для пошуку в адмінці.
 */
final readonly class MongoSupplierRepository implements SupplierRepository
{
    public const COLLECTION = 'suppliers';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function save(Supplier $supplier): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => $supplier->id()],
            ['$set' => $this->toDocument($supplier)],
            ['upsert' => true],
        );

        $this->connection->manager()->executeBulkWrite(
            $this->connection->namespaceFor(self::COLLECTION),
            $bulk,
        );
    }

    public function findById(string $id): ?Supplier
    {
        return $this->findOne(['_id' => $id]);
    }

    public function findByName(string $name): ?Supplier
    {
        return $this->findOne([
            'nameLower' => mb_strtolower(trim($name), 'UTF-8'),
            'archivedAt' => null,
        ]);
    }

    public function findByEdrpou(string $edrpou): ?Supplier
    {
        return $this->findOne(['edrpou' => $edrpou, 'archivedAt' => null]);
    }

    public function search(
        ?string $query = null,
        ?SupplierStatus $status = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespaceFor(self::COLLECTION),
            new \MongoDB\Driver\Query(
                $this->filter($query, $status),
                ['sort' => ['name' => 1], 'limit' => $limit, 'skip' => $offset],
            ),
        );
        $cursor->setTypeMap(MongoCodec::TYPE_MAP);

        $result = [];

        foreach ($cursor as $document) {
            /** @var array<string, mixed> $document */
            $result[] = $this->hydrate($document);
        }

        return $result;
    }

    public function count(?string $query = null, ?SupplierStatus $status = null): int
    {
        $command = new \MongoDB\Driver\Command([
            'count' => self::COLLECTION,
            'query' => $this->filter($query, $status),
        ]);

        $cursor = $this->connection->manager()->executeCommand($this->connection->database(), $command);
        $cursor->setTypeMap(MongoCodec::TYPE_MAP);
        $response = current($cursor->toArray());

        return \is_array($response) ? (int) ($response['n'] ?? 0) : 0;
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
    private function findOne(array $filter): ?Supplier
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
    private function filter(?string $query, ?SupplierStatus $status): array
    {
        $filter = ['archivedAt' => null];

        if (null !== $status) {
            $filter['status'] = $status->value;
        }

        if (null !== $query && '' !== trim($query)) {
            $escaped = preg_quote(trim($query), '/');
            $filter['$or'] = [
                ['name' => new \MongoDB\BSON\Regex($escaped, 'i')],
                ['edrpou' => new \MongoDB\BSON\Regex($escaped, 'i')],
            ];
        }

        return $filter;
    }

    /**
     * @return array<string, mixed>
     */
    private function toDocument(Supplier $supplier): array
    {
        return [
            'name' => $supplier->name(),
            // Денормалізований нижній регістр — щоб унікальність назви
            // не залежала від регістру без collation-залежних індексів.
            'nameLower' => mb_strtolower($supplier->name(), 'UTF-8'),
            'edrpou' => $supplier->edrpou(),
            'status' => $supplier->status()->value,
            'active' => $supplier->isActive(),
            'storeAccess' => $supplier->storeAccess()->toArray(),
            'contacts' => array_map(
                static fn (SupplierContact $contact): array => $contact->toArray(),
                $supplier->contacts(),
            ),
            'suspendedAt' => MongoCodec::toBson($supplier->suspendedAt()),
            'suspendReason' => $supplier->suspendReason(),
            'archivedAt' => MongoCodec::toBson($supplier->archivedAt()),
            'createdAt' => MongoCodec::toBson($supplier->createdAt()),
            'updatedAt' => MongoCodec::toBson($supplier->updatedAt()),
            'schemaVersion' => $supplier->schemaVersion(),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function hydrate(array $document): Supplier
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        /** @var array{allStores?: bool, storeIds?: list<string>} $access */
        $access = \is_array($document['storeAccess'] ?? null) ? $document['storeAccess'] : ['allStores' => true];
        $storeAccess = ($access['allStores'] ?? true)
            ? StoreAccess::allStores()
            : StoreAccess::whitelist(array_values($access['storeIds'] ?? []));

        $contacts = [];

        foreach ((array) ($document['contacts'] ?? []) as $contact) {
            if (\is_array($contact)) {
                $contacts[] = new SupplierContact(
                    name: (string) ($contact['name'] ?? ''),
                    phone: isset($contact['phone']) ? (string) $contact['phone'] : null,
                    email: isset($contact['email']) ? (string) $contact['email'] : null,
                );
            }
        }

        return Supplier::reconstitute(
            id: (string) $document['_id'],
            name: (string) $document['name'],
            edrpou: isset($document['edrpou']) ? (string) $document['edrpou'] : null,
            status: SupplierStatus::from((string) ($document['status'] ?? SupplierStatus::Active->value)),
            storeAccess: $storeAccess,
            contacts: $contacts,
            createdAt: MongoCodec::toPhpRequired($document['createdAt'] ?? null, $now),
            updatedAt: MongoCodec::toPhpRequired($document['updatedAt'] ?? null, $now),
            suspendedAt: MongoCodec::toPhp($document['suspendedAt'] ?? null),
            suspendReason: isset($document['suspendReason']) ? (string) $document['suspendReason'] : null,
            archivedAt: MongoCodec::toPhp($document['archivedAt'] ?? null),
            schemaVersion: (int) ($document['schemaVersion'] ?? 1),
        );
    }
}
