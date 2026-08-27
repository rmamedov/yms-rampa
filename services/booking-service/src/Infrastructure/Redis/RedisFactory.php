<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Redis;
use RuntimeException;

/**
 * Фабрика зʼєднання Redis для холдів слотів і кешу конфігурації магазинів.
 * Винесена окремо, щоб контейнер не залежав від наявності ext-redis
 * на етапі компіляції.
 */
final readonly class RedisFactory
{
    public static function create(string $dsn = 'redis://127.0.0.1:6379'): Redis
    {
        if (!\extension_loaded('redis')) {
            throw new RuntimeException('PHP-розширення redis не встановлено');
        }

        $parts = parse_url($dsn);

        if (false === $parts) {
            throw new RuntimeException(\sprintf('Некоректний DSN Redis: %s', $dsn));
        }

        $redis = new Redis();
        $redis->connect($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 6379);

        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }

        if (isset($parts['path']) && '' !== trim($parts['path'], '/')) {
            $redis->select((int) trim($parts['path'], '/'));
        }

        return $redis;
    }
}
