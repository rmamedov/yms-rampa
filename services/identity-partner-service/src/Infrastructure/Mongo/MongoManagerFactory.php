<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Фабрика зʼєднання з MongoDB для прод-режиму.
 *
 * Свідомо НЕ реєструється в контейнері за замовчуванням: на машині розробника
 * MongoDB-сервера може не бути, а сервіс має підніматись і проходити тести на
 * InMemory-реалізаціях.
 */
final class MongoManagerFactory
{
    public static function create(string $uri): \MongoDB\Driver\Manager
    {
        MongoSupport::assertDriverAvailable();

        return new \MongoDB\Driver\Manager($uri);
    }

    /** Швидка перевірка живого сервера — використовується інтеграційними тестами. */
    public static function isServerReachable(string $uri, int $timeoutMs = 500): bool
    {
        if (!MongoSupport::isDriverAvailable()) {
            return false;
        }

        try {
            $manager = new \MongoDB\Driver\Manager($uri, ['serverSelectionTimeoutMS' => $timeoutMs, 'connectTimeoutMS' => $timeoutMs]);
            $manager->executeCommand('admin', new \MongoDB\Driver\Command(['ping' => 1]));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
