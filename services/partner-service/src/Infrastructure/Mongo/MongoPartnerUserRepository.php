<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\PartnerUser\PartnerUser;
use App\Domain\PartnerUser\PartnerUserRepository;
use App\Domain\PartnerUser\PartnerUserType;
use App\Domain\Shared\ConflictException;

/**
 * Сховище профілів партнерського контуру, колекція `partners.partner_users`
 * (розділ 10.4).
 *
 * DATA-17: unique partial `{phone:1}` з фільтром `{type:"driver", archivedAt:null}` —
 * телефон водія унікальний глобально; порушення (11000) перекладається
 * в доменний ConflictException з текстом SUP-DRV-02.
 * DATA-35: жодного поля з паролем тут немає.
 */
final readonly class MongoPartnerUserRepository implements PartnerUserRepository
{
    public const COLLECTION = 'partner_users';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function save(PartnerUser $user): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => $user->id()],
            ['$set' => $this->toDocument($user)],
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
                    'Водій з таким телефоном уже зареєстрований.',
                    'DRIVER_PHONE_DUPLICATE',
                );
            }

            throw $e;
        }
    }

    public function findById(string $id): ?PartnerUser
    {
        return $this->findOne(['_id' => $id]);
    }

    public function findByAccountId(string $accountId): ?PartnerUser
    {
        return $this->findOne(['accountId' => $accountId]);
    }

    public function findDriverByPhone(string $phone): ?PartnerUser
    {
        // Свідомо БЕЗ supplierId: телефон водія унікальний у всьому контурі.
        return $this->findOne([
            'phone' => $phone,
            'type' => PartnerUserType::Driver->value,
            'archivedAt' => null,
        ]);
    }

    public function listBySupplier(
        string $supplierId,
        ?PartnerUserType $type = null,
        bool $includeInactive = true,
    ): array {
        $filter = ['supplierId' => $supplierId, 'archivedAt' => null];

        if (null !== $type) {
            $filter['type'] = $type->value;
        }

        if (!$includeInactive) {
            $filter['active'] = true;
        }

        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespaceFor(self::COLLECTION),
            new \MongoDB\Driver\Query($filter, ['sort' => ['lastName' => 1, 'firstName' => 1]]),
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
    private function findOne(array $filter): ?PartnerUser
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
    private function toDocument(PartnerUser $user): array
    {
        return [
            'accountId' => $user->accountId(),
            'type' => $user->type()->value,
            'supplierId' => $user->supplierId(),
            'phone' => $user->phone(),
            'firstName' => $user->firstName(),
            'lastName' => $user->lastName(),
            'defaultVehicleId' => $user->defaultVehicleId(),
            'active' => $user->isActive(),
            'archivedAt' => MongoCodec::toBson($user->archivedAt()),
            'createdAt' => MongoCodec::toBson($user->createdAt()),
            'updatedAt' => MongoCodec::toBson($user->updatedAt()),
            'schemaVersion' => $user->schemaVersion(),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function hydrate(array $document): PartnerUser
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return PartnerUser::reconstitute(
            id: (string) $document['_id'],
            accountId: (string) ($document['accountId'] ?? ''),
            type: PartnerUserType::from((string) $document['type']),
            supplierId: (string) $document['supplierId'],
            phone: isset($document['phone']) ? (string) $document['phone'] : null,
            firstName: isset($document['firstName']) ? (string) $document['firstName'] : null,
            lastName: isset($document['lastName']) ? (string) $document['lastName'] : null,
            defaultVehicleId: isset($document['defaultVehicleId']) ? (string) $document['defaultVehicleId'] : null,
            active: (bool) ($document['active'] ?? true),
            createdAt: MongoCodec::toPhpRequired($document['createdAt'] ?? null, $now),
            updatedAt: MongoCodec::toPhpRequired($document['updatedAt'] ?? null, $now),
            archivedAt: MongoCodec::toPhp($document['archivedAt'] ?? null),
            schemaVersion: (int) ($document['schemaVersion'] ?? 2),
        );
    }
}
