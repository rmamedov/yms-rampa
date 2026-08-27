<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\ReservedSlotRuleRepository;
use App\Infrastructure\Mongo\Mapper\ConfigurationDocumentMapper;

/**
 * Правила резервування слотів у колекції `reserved_slot_rules` (10.2.3).
 */
final readonly class MongoReservedSlotRuleRepository implements ReservedSlotRuleRepository
{
    public const string COLLECTION = 'reserved_slot_rules';

    public function __construct(
        private MongoConnection $connection,
    ) {
    }

    public function save(ReservedSlotRule $rule): void
    {
        $this->connection->upsert(self::COLLECTION, $rule->id, ConfigurationDocumentMapper::ruleToDocument($rule));
    }

    public function find(string $id): ?ReservedSlotRule
    {
        $documents = $this->connection->find(self::COLLECTION, ['_id' => $id], ['limit' => 1]);

        return [] === $documents ? null : ConfigurationDocumentMapper::ruleFromDocument($documents[0]);
    }

    public function findForStore(string $storeId, ?bool $activeOnly = null): array
    {
        $filter = ['storeId' => $storeId];

        if (null !== $activeOnly) {
            $filter['active'] = $activeOnly;
        }

        return array_map(
            ConfigurationDocumentMapper::ruleFromDocument(...),
            $this->connection->find(self::COLLECTION, $filter, ['sort' => ['slotStartTime' => 1]]),
        );
    }

    public function findForSupplier(string $supplierId): array
    {
        return array_map(
            ConfigurationDocumentMapper::ruleFromDocument(...),
            $this->connection->find(self::COLLECTION, ['supplierId' => $supplierId]),
        );
    }

    public function delete(string $id): void
    {
        $this->connection->deleteOne(self::COLLECTION, ['_id' => $id]);
    }
}
