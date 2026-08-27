<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Auth\AccessTokenDenylist;
use App\Domain\Clock\Clock;

/**
 * Redis-denylist access-токенів партнерського контуру (AUTH-28, AUTH-32).
 *
 * `jti` заноситься з TTL = залишок життя токена, тому запис зникає сам після
 * `exp`. Префікс ключа окремий від staff-контуру (AUTH-01: контури ізольовані
 * навіть у спільному Redis).
 *
 * Розширення ext-redis може бути відсутнє, тому клас виключений з
 * автореєстрації в config/services.yaml і підключається лише прод-профілем.
 */
final readonly class RedisAccessTokenDenylist implements AccessTokenDenylist
{
    public function __construct(
        private \Redis $redis,
        private Clock $clock,
        private string $keyPrefix = 'yms:partner:jti:',
    ) {
    }

    public static function isDriverAvailable(): bool
    {
        return \extension_loaded('redis') && class_exists(\Redis::class);
    }

    public function revoke(string $jti, \DateTimeImmutable $expiresAt): void
    {
        if ('' === $jti) {
            return;
        }

        $ttl = $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();

        if ($ttl <= 0) {
            // Токен уже прострочений — заносити немає сенсу.
            return;
        }

        $this->redis->setex($this->keyPrefix.$jti, $ttl, '1');
    }

    public function isRevoked(string $jti): bool
    {
        if ('' === $jti) {
            return false;
        }

        return (bool) $this->redis->exists($this->keyPrefix.$jti);
    }
}
