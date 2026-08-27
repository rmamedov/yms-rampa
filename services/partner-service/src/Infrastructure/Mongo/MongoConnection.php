<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use MongoDB\Driver\Manager;

/**
 * Ліниве з'єднання з MongoDB через ext-mongodb.
 *
 * Свідомо не тягнемо бібліотеку mongodb/mongodb: сервісу достатньо
 * низькорівневого драйвера. Manager створюється лише при першому
 * реальному зверненні, тому контейнер Symfony збирається і `bin/console`
 * працює навіть без встановленого розширення та без піднятого сервера.
 */
final class MongoConnection
{
    private ?Manager $manager = null;

    public function __construct(
        private readonly string $dsn = 'mongodb://127.0.0.1:27017',
        private readonly string $database = 'partners',
        /**
         * Обмежуємо очікування вибору сервера: із дефолтними 30 с консольні
         * команди на машині без піднятої Mongo «висли б» на пів хвилини.
         */
        private readonly int $serverSelectionTimeoutMs = 3000,
    ) {
    }

    /**
     * Чи можна взагалі працювати з Mongo в цьому середовищі.
     * Перевірка в рантаймі, а не через `composer require ext-mongodb`,
     * щоб відсутність розширення не ламала автозавантаження і тести.
     */
    public static function isDriverAvailable(): bool
    {
        return \extension_loaded('mongodb') && class_exists(Manager::class);
    }

    public function database(): string
    {
        return $this->database;
    }

    public function namespaceFor(string $collection): string
    {
        return $this->database.'.'.$collection;
    }

    public function manager(): Manager
    {
        if (!self::isDriverAvailable()) {
            throw new \RuntimeException(
                'Розширення PHP «mongodb» не встановлено — робота з MongoDB неможлива. '
                .'Для запуску без бази використовуйте InMemory-реалізації репозиторіїв.',
            );
        }

        return $this->manager ??= new Manager(
            $this->dsn,
            ['serverSelectionTimeoutMS' => $this->serverSelectionTimeoutMs],
        );
    }

    /**
     * М'яка перевірка доступності сервера (використовується командами
     * та інтеграційними тестами, щоб коректно пропускатись).
     */
    public function isServerReachable(): bool
    {
        if (!self::isDriverAvailable()) {
            return false;
        }

        try {
            $command = new \MongoDB\Driver\Command(['ping' => 1]);
            $this->manager()->executeCommand($this->database, $command);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
