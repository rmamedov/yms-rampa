<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Створення індексів БД `stores` згідно з розділом 10.2.
 */
final readonly class MongoIndexInitializer
{
    public function __construct(
        private MongoConnection $connection,
    ) {
    }

    /**
     * @return list<string> перелік створених індексів для звіту команди
     */
    public function createAll(): array
    {
        $created = [];

        // 10.2.1 branches
        $created[] = $this->createIndexes(MongoBranchRepository::COLLECTION, [
            ['key' => ['externalId' => 1], 'name' => 'externalId_unique', 'unique' => true],
            ['key' => ['city' => 1, 'ymsStatus' => 1, 'visibleToSuppliers' => 1], 'name' => 'supplier_catalog'],
            ['key' => ['location' => '2dsphere'], 'name' => 'location_2dsphere'],
            ['key' => ['ymsStatus' => 1, 'syncedAt' => 1], 'name' => 'stale_sync'],
        ]);

        // 10.2.2 store_configs
        $created[] = $this->createIndexes(MongoStoreConfigurationRepository::COLLECTION, [
            ['key' => ['storeId' => 1, 'version' => 1], 'name' => 'store_version_unique', 'unique' => true],
            ['key' => ['storeId' => 1, 'effectiveFrom' => -1], 'name' => 'store_effective'],
        ]);

        // 10.2.3 reserved_slot_rules
        $created[] = $this->createIndexes(MongoReservedSlotRuleRepository::COLLECTION, [
            ['key' => ['storeId' => 1, 'dayOfWeek' => 1, 'active' => 1], 'name' => 'store_weekly'],
            ['key' => ['storeId' => 1, 'date' => 1, 'active' => 1], 'name' => 'store_dated'],
            ['key' => ['supplierId' => 1], 'name' => 'supplier'],
        ]);

        // 10.2.3 slot_blocks
        $created[] = $this->createIndexes(MongoSlotBlockRepository::COLLECTION, [
            ['key' => ['storeId' => 1, 'blockFrom' => 1, 'blockTo' => 1], 'name' => 'store_range'],
        ]);

        // 10.2.3 sync_log — TTL 180 днів (зберігання журналу мінімум 90 днів, INT-11).
        $created[] = $this->createIndexes(MongoSyncLogRepository::COLLECTION, [
            ['key' => ['startedAt' => -1], 'name' => 'started_desc'],
            ['key' => ['startedAt' => 1], 'name' => 'sync_log_ttl', 'expireAfterSeconds' => 15552000],
        ]);

        return $created;
    }

    /**
     * @param list<array<string, mixed>> $indexes
     */
    private function createIndexes(string $collection, array $indexes): string
    {
        $this->connection->command([
            'createIndexes' => $collection,
            'indexes' => $indexes,
        ]);

        return \sprintf('%s: %d індексів', $collection, \count($indexes));
    }
}
