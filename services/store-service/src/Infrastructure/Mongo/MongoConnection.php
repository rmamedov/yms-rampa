<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Shared\DomainException;

/**
 * Тонка обгортка над драйвером ext-mongodb.
 *
 * Свідомо не використовує пакет mongodb/mongodb і doctrine/mongodb-odm, щоб
 * відсутність розширення чи сервера MongoDB не ламала автозавантаження, компіляцію
 * контейнера і юніт-тести: перевірка наявності виконується в рантаймі (isAvailable()).
 */
final class MongoConnection
{
    private ?object $manager = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $database,
    ) {
    }

    /** Чи доступний драйвер MongoDB у цьому середовищі. */
    public static function isAvailable(): bool
    {
        return \extension_loaded('mongodb') && class_exists('MongoDB\Driver\Manager');
    }

    public function database(): string
    {
        return $this->database;
    }

    public function dsn(): string
    {
        return $this->dsn;
    }

    /**
     * @return \MongoDB\Driver\Manager
     */
    public function manager(): object
    {
        if (!self::isAvailable()) {
            throw MongoUnavailableException::extensionMissing();
        }

        if (null === $this->manager) {
            /** @var class-string $managerClass */
            $managerClass = 'MongoDB\Driver\Manager';
            $this->manager = new $managerClass($this->dsn);
        }

        return $this->manager;
    }

    /** Перевірка живого зʼєднання — використовується інтеграційними тестами. */
    public function ping(): bool
    {
        try {
            $this->command(['ping' => 1]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    public function find(string $collection, array $filter = [], array $options = []): array
    {
        /** @var class-string $queryClass */
        $queryClass = 'MongoDB\Driver\Query';
        $cursor = $this->manager()->executeQuery(
            $this->namespace($collection),
            new $queryClass($filter, $options),
        );
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        /** @var list<array<string, mixed>> $documents */
        $documents = $cursor->toArray();

        return $documents;
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function countDocuments(string $collection, array $filter = []): int
    {
        $result = $this->command(['count' => $collection, 'query' => (object) $filter]);

        return (int) ($result[0]['n'] ?? 0);
    }

    /**
     * Upsert одного документа за _id.
     *
     * @param array<string, mixed> $document
     */
    public function upsert(string $collection, string $id, array $document): void
    {
        $this->bulkUpsert($collection, [$id => $document]);
    }

    /**
     * @param array<string, array<string, mixed>> $documents ключ — _id
     */
    public function bulkUpsert(string $collection, array $documents): void
    {
        if ([] === $documents) {
            return;
        }

        /** @var class-string $bulkClass */
        $bulkClass = 'MongoDB\Driver\BulkWrite';
        $bulk = new $bulkClass();

        foreach ($documents as $id => $document) {
            $document['_id'] = $id;
            $bulk->update(['_id' => $id], ['$set' => $document], ['upsert' => true]);
        }

        $this->manager()->executeBulkWrite($this->namespace($collection), $bulk);
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function deleteOne(string $collection, array $filter): void
    {
        /** @var class-string $bulkClass */
        $bulkClass = 'MongoDB\Driver\BulkWrite';
        $bulk = new $bulkClass();
        $bulk->delete($filter, ['limit' => 1]);

        $this->manager()->executeBulkWrite($this->namespace($collection), $bulk);
    }

    /**
     * @param array<string, mixed> $pipeline
     *
     * @return list<array<string, mixed>>
     */
    public function aggregate(string $collection, array $pipeline): array
    {
        return $this->command([
            'aggregate' => $collection,
            'pipeline' => $pipeline,
            'cursor' => (object) [],
        ]);
    }

    /**
     * @param array<string, mixed> $command
     *
     * @return list<array<string, mixed>>
     */
    public function command(array $command): array
    {
        if (!self::isAvailable()) {
            throw MongoUnavailableException::extensionMissing();
        }

        /** @var class-string $commandClass */
        $commandClass = 'MongoDB\Driver\Command';
        $cursor = $this->manager()->executeCommand($this->database, new $commandClass($command));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        /** @var list<array<string, mixed>> $result */
        $result = $cursor->toArray();

        if (isset($result[0]['cursor']['firstBatch'])) {
            /** @var list<array<string, mixed>> $batch */
            $batch = $result[0]['cursor']['firstBatch'];

            return $batch;
        }

        return $result;
    }

    public function namespace(string $collection): string
    {
        return $this->database.'.'.$collection;
    }

    /** Конвертація BSON UTCDateTime → DateTimeImmutable у UTC (DATA-01). */
    public static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'));
        }

        if (\is_object($value) && method_exists($value, 'toDateTime')) {
            return \DateTimeImmutable::createFromInterface($value->toDateTime())
                ->setTimezone(new \DateTimeZone('UTC'));
        }

        if (\is_string($value) && '' !== $value) {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        throw new class('Непідтримуваний формат дати в документі MongoDB', 'MONGO_DATE_FORMAT') extends DomainException {
            public function httpStatus(): int
            {
                return 500;
            }

            public function title(): string
            {
                return 'Помилка читання документа';
            }
        };
    }

    /** Конвертація DateTimeImmutable → BSON UTCDateTime. */
    public static function fromDateTime(?\DateTimeInterface $value): ?object
    {
        if (!$value instanceof \DateTimeInterface) {
            return null;
        }

        /** @var class-string $utcClass */
        $utcClass = 'MongoDB\BSON\UTCDateTime';

        return new $utcClass((int) $value->format('Uv'));
    }
}
