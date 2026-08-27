<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Ліниве зʼєднання з MongoDB через низькорівневий драйвер ext-mongodb.
 *
 * Клас навмисно НЕ типізує конструктор класами драйвера: якщо розширення
 * ext-mongodb на машині відсутнє, автозавантаження, компіляція контейнера
 * і юніт-тести на InMemory-реалізаціях працюють без помилок, а падіння
 * відбувається лише в момент реального звернення до бази.
 */
final class MongoConnection
{
    private ?object $manager = null;

    public function __construct(
        private readonly string $dsn = 'mongodb://127.0.0.1:27017',
        private readonly string $database = 'yms_analytics',
    ) {
    }

    /** Чи доступний драйвер MongoDB у цьому середовищі. */
    public static function isDriverAvailable(): bool
    {
        return extension_loaded('mongodb') && class_exists('MongoDB\Driver\Manager');
    }

    public function database(): string
    {
        return $this->database;
    }

    public function namespaceFor(string $collection): string
    {
        return $this->database . '.' . $collection;
    }

    /**
     * @return \MongoDB\Driver\Manager
     */
    public function manager(): object
    {
        if (!self::isDriverAvailable()) {
            throw new \RuntimeException(
                'Розширення PHP ext-mongodb не встановлено: MongoDB-сховище недоступне. '
                . 'Для роботи без бази використовуйте InMemory-реалізації репозиторіїв.',
            );
        }

        /** @var class-string $managerClass */
        $managerClass = 'MongoDB\Driver\Manager';

        return $this->manager ??= new $managerClass($this->dsn);
    }
}
