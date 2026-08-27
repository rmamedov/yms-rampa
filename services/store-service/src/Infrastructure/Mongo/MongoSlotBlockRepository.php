<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\SlotBlockRepository;
use App\Infrastructure\Mongo\Mapper\ConfigurationDocumentMapper;

/**
 * Разові блокування слотів у колекції `slot_blocks` (10.2.3).
 */
final readonly class MongoSlotBlockRepository implements SlotBlockRepository
{
    public const string COLLECTION = 'slot_blocks';

    public function __construct(
        private MongoConnection $connection,
    ) {
    }

    public function save(SlotBlock $block): void
    {
        $this->connection->upsert(self::COLLECTION, $block->id, ConfigurationDocumentMapper::blockToDocument($block));
    }

    public function find(string $id): ?SlotBlock
    {
        $documents = $this->connection->find(self::COLLECTION, ['_id' => $id], ['limit' => 1]);

        return [] === $documents ? null : ConfigurationDocumentMapper::blockFromDocument($documents[0]);
    }

    public function findForStore(string $storeId, ?bool $activeOnly = null): array
    {
        $filter = ['storeId' => $storeId];

        if (true === $activeOnly) {
            $filter['releasedAt'] = null;
        } elseif (false === $activeOnly) {
            $filter['releasedAt'] = ['$ne' => null];
        }

        return array_map(
            ConfigurationDocumentMapper::blockFromDocument(...),
            $this->connection->find(self::COLLECTION, $filter, ['sort' => ['blockFrom' => 1]]),
        );
    }

    public function findOverlapping(string $storeId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(
            ConfigurationDocumentMapper::blockFromDocument(...),
            $this->connection->find(self::COLLECTION, [
                'storeId' => $storeId,
                'releasedAt' => null,
                'blockFrom' => ['$lt' => MongoConnection::fromDateTime($to)],
                'blockTo' => ['$gt' => MongoConnection::fromDateTime($from)],
            ]),
        );
    }

    public function delete(string $id): void
    {
        $this->connection->deleteOne(self::COLLECTION, ['_id' => $id]);
    }
}
