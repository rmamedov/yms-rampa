<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Тонка обгортка над низькорівневим драйвером ext-mongodb.
 *
 * Реалізації репозиторіїв partner-контуру працюють із БД `identity_partner`
 * (10.6). Обгортка навмисно мінімальна: увесь код лежить в Infrastructure, а
 * домен нічого не знає про MongoDB.
 *
 * Клас не інстанціюється, якщо розширення відсутнє — тому автозавантаження і
 * юніт-тести на InMemory-реалізаціях працюють на машині без MongoDB.
 */
abstract class MongoSupport
{
    /** @var array<string, string> */
    protected const array TYPE_MAP = [
        'root' => 'array',
        'document' => 'array',
        'array' => 'array',
    ];

    public function __construct(
        protected readonly \MongoDB\Driver\Manager $manager,
        protected readonly string $database = 'identity_partner',
    ) {
        self::assertDriverAvailable();
    }

    /** Чи доступне розширення ext-mongodb у цьому середовищі. */
    public static function isDriverAvailable(): bool
    {
        return \extension_loaded('mongodb') && class_exists(\MongoDB\Driver\Manager::class);
    }

    public static function assertDriverAvailable(): void
    {
        if (!self::isDriverAvailable()) {
            throw new \RuntimeException('Розширення ext-mongodb недоступне — використайте InMemory-реалізації репозиторіїв.');
        }
    }

    abstract protected function collection(): string;

    protected function namespaceString(): string
    {
        return $this->database.'.'.$this->collection();
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    protected function find(array $filter, array $options = []): array
    {
        $cursor = $this->manager->executeQuery(
            $this->namespaceString(),
            new \MongoDB\Driver\Query($filter, $options),
        );
        $cursor->setTypeMap(self::TYPE_MAP);

        /** @var list<array<string, mixed>> $documents */
        $documents = $cursor->toArray();

        return $documents;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    protected function findOne(array $filter, array $options = []): ?array
    {
        $documents = $this->find($filter, $options + ['limit' => 1]);

        return $documents[0] ?? null;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    protected function upsert(array $filter, array $update): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update($filter, $update, ['upsert' => true]);

        $this->manager->executeBulkWrite($this->namespaceString(), $bulk);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    protected function updateMany(array $filter, array $update): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update($filter, $update, ['multi' => true]);

        $this->manager->executeBulkWrite($this->namespaceString(), $bulk);
    }

    /** @param array<string, mixed> $filter */
    protected function deleteMany(array $filter): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->delete($filter, ['limit' => 0]);

        $this->manager->executeBulkWrite($this->namespaceString(), $bulk);
    }

    /** @param array<string, mixed> $document */
    protected function insert(array $document): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->insert($document);

        $this->manager->executeBulkWrite($this->namespaceString(), $bulk);
    }

    /** DATA-01: у BSON час зберігається як UTC-дата. */
    protected static function toBson(?\DateTimeImmutable $date): ?\MongoDB\BSON\UTCDateTime
    {
        return null === $date ? null : new \MongoDB\BSON\UTCDateTime($date);
    }

    protected static function fromBson(mixed $value): ?\DateTimeImmutable
    {
        if (!$value instanceof \MongoDB\BSON\UTCDateTime) {
            return null;
        }

        return $value->toDateTimeImmutable()->setTimezone(new \DateTimeZone('UTC'));
    }
}
