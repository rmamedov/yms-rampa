<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Auth\AccessTokenDenylist;
use App\Domain\Shared\Clock;

/**
 * Redis-denylist access-токенів (AUTH-17, AUTH-28, AUTH-32).
 *
 * `jti` заноситься з TTL = залишок життя токена, тому запис зникає сам
 * після `exp`. Розширення ext-redis може бути відсутнє — клас виключений
 * з автоварінгу і перевіряє наявність розширення в рантаймі.
 */
final readonly class RedisAccessTokenDenylist implements AccessTokenDenylist
{
    public function __construct(
        private \Redis $redis,
        private Clock $clock,
        private string $keyPrefix = 'yms:staff:jti:',
    ) {
    }

    public static function isDriverAvailable(): bool
    {
        return \extension_loaded('redis') && class_exists(\Redis::class);
    }

    public function revoke(string $jti, \DateTimeImmutable $expiresAt): void
    {
        $ttl = $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();

        if ($ttl <= 0) {
            // Токен уже прострочений — заносити немає сенсу
            return;
        }

        $this->redis->setex($this->keyPrefix.$jti, $ttl, '1');
    }

    public function isRevoked(string $jti): bool
    {
        return (bool) $this->redis->exists($this->keyPrefix.$jti);
    }
}
