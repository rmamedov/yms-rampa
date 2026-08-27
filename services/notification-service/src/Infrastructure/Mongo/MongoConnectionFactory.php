<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Exception\DomainException;

/**
 * Ліниве підключення до MongoDB.
 *
 * Клієнт створюється лише в момент першого звернення до колекції, тому
 * відсутність розширення ext-mongodb або самого сервера НЕ ламає
 * автозавантаження, збірку контейнера і юніт-тести — вони працюють на
 * InMemory-реалізаціях.
 */
final class MongoConnectionFactory
{
    private ?object $client = null;

    public function __construct(
        private readonly string $uri = 'mongodb://localhost:27017',
        private readonly string $database = 'yms_notification',
    ) {
    }

    /** Чи доступні розширення і бібліотека MongoDB у цьому середовищі. */
    public static function isAvailable(): bool
    {
        return \extension_loaded('mongodb') && class_exists('MongoDB\Client');
    }

    public function databaseName(): string
    {
        return $this->database;
    }

    /**
     * @return \MongoDB\Collection
     */
    public function collection(string $name): object
    {
        if (!self::isAvailable()) {
            throw new DomainException(
                'MongoDB недоступна: не встановлено розширення ext-mongodb або бібліотеку mongodb/mongodb.',
                'MONGODB_UNAVAILABLE',
                503,
            );
        }

        if (null === $this->client) {
            /** @var class-string $clientClass */
            $clientClass = 'MongoDB\Client';
            $this->client = new $clientClass($this->uri);
        }

        /** @var \MongoDB\Client $client */
        $client = $this->client;

        return $client->selectCollection($this->database, $name, [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
    }
}
