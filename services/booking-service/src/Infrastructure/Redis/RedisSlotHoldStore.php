<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Hold\Exception\HoldExpiredException;
use App\Domain\Hold\Exception\HoldNotOwnedException;
use App\Domain\Hold\Exception\SlotHeldException;
use App\Domain\Hold\SlotHold;
use App\Domain\Hold\SlotHoldStore;
use App\Domain\Slot\SlotKey;
use DateTimeImmutable;
use DateTimeZone;
use Redis;

/**
 * Холди слотів у Redis (HOLD-01..HOLD-04).
 *
 * Створення — атомарний `SET NX EX`, тому на слот фізично не може існувати
 * двох активних холдів. Втрата Redis не призводить до подвійних бронювань:
 * фінальну гарантію дає частковий унікальний індекс MongoDB (HOLD-04).
 */
final readonly class RedisSlotHoldStore implements SlotHoldStore
{
    public const string KEY_PREFIX = 'yms:hold:';

    public function __construct(private Redis $redis)
    {
    }

    public function acquire(
        SlotKey $slotKey,
        string $ownerUserId,
        ?string $supplierId,
        DateTimeImmutable $now,
        int $ttlSeconds,
        int $maxMinutes,
    ): SlotHold {
        $maxExpiresAt = $now->modify(\sprintf('+%d minutes', $maxMinutes));
        $ttl = min($ttlSeconds, max(1, $maxExpiresAt->getTimestamp() - $now->getTimestamp()));

        $hold = new SlotHold(
            slotKey: $slotKey,
            holdToken: bin2hex(random_bytes(16)),
            ownerUserId: $ownerUserId,
            supplierId: $supplierId,
            createdAt: $now,
            expiresAt: $now->modify(\sprintf('+%d seconds', $ttl)),
            maxExpiresAt: $maxExpiresAt,
        );

        $stored = $this->redis->set(self::key($slotKey), self::encode($hold), ['NX', 'EX' => $ttl]);

        if (true !== $stored) {
            throw new SlotHeldException();
        }

        return $hold;
    }

    public function extend(
        SlotKey $slotKey,
        string $holdToken,
        DateTimeImmutable $now,
        int $ttlSeconds,
        int $maxMinutes,
    ): SlotHold {
        $hold = $this->get($slotKey, $now);

        if (null === $hold) {
            throw new HoldExpiredException();
        }

        if (!$hold->isOwnedBy($holdToken)) {
            throw new HoldNotOwnedException();
        }

        $secondsLeftOfLimit = $hold->maxExpiresAt->getTimestamp() - $now->getTimestamp();

        // HOLD-02: сумарна тривалість однієї hold обмежена holdMaxMinutes.
        if ($secondsLeftOfLimit <= 0) {
            $this->redis->del(self::key($slotKey));

            throw new HoldExpiredException();
        }

        $ttl = min($ttlSeconds, $secondsLeftOfLimit);
        $extended = new SlotHold(
            slotKey: $hold->slotKey,
            holdToken: $hold->holdToken,
            ownerUserId: $hold->ownerUserId,
            supplierId: $hold->supplierId,
            createdAt: $hold->createdAt,
            expiresAt: $now->modify(\sprintf('+%d seconds', $ttl)),
            maxExpiresAt: $hold->maxExpiresAt,
        );

        $this->redis->setex(self::key($slotKey), $ttl, self::encode($extended));

        return $extended;
    }

    public function release(SlotKey $slotKey, string $holdToken): void
    {
        $raw = $this->redis->get(self::key($slotKey));

        if (!\is_string($raw)) {
            return;
        }

        $payload = self::decode($raw);

        if (null === $payload || !hash_equals((string) $payload['holdToken'], $holdToken)) {
            throw new HoldNotOwnedException();
        }

        $this->redis->del(self::key($slotKey));
    }

    public function get(SlotKey $slotKey, DateTimeImmutable $now): ?SlotHold
    {
        $key = self::key($slotKey);
        $raw = $this->redis->get($key);

        if (!\is_string($raw)) {
            return null;
        }

        $payload = self::decode($raw);

        if (null === $payload) {
            return null;
        }

        $ttl = $this->redis->ttl($key);
        $ttl = \is_int($ttl) && $ttl > 0 ? $ttl : 0;

        if (0 === $ttl) {
            return null;
        }

        return self::hydrate($slotKey, $payload, $now->modify(\sprintf('+%d seconds', $ttl)));
    }

    public function activeKeys(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        $pattern = self::KEY_PREFIX.$storeId.'|*';
        $iterator = null;
        $keys = [];

        do {
            $batch = $this->redis->scan($iterator, $pattern, 500);

            if (false === $batch) {
                break;
            }

            foreach ($batch as $redisKey) {
                $slotKeyString = substr((string) $redisKey, \strlen(self::KEY_PREFIX));
                $parts = explode('|', $slotKeyString);

                if (3 !== \count($parts)) {
                    continue;
                }

                $slotStart = new DateTimeImmutable($parts[2], new DateTimeZone('UTC'));

                if ($slotStart >= $from && $slotStart < $to) {
                    $keys[] = $slotKeyString;
                }
            }
        } while ($iterator > 0);

        return array_values(array_unique($keys));
    }

    private static function key(SlotKey $slotKey): string
    {
        return self::KEY_PREFIX.$slotKey->toString();
    }

    private static function encode(SlotHold $hold): string
    {
        return json_encode([
            'holdToken' => $hold->holdToken,
            'ownerUserId' => $hold->ownerUserId,
            'supplierId' => $hold->supplierId,
            'createdAt' => $hold->createdAt->getTimestamp(),
            'maxExpiresAt' => $hold->maxExpiresAt->getTimestamp(),
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) && isset($decoded['holdToken']) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function hydrate(SlotKey $slotKey, array $payload, DateTimeImmutable $expiresAt): SlotHold
    {
        $utc = new DateTimeZone('UTC');

        return new SlotHold(
            slotKey: $slotKey,
            holdToken: (string) $payload['holdToken'],
            ownerUserId: (string) ($payload['ownerUserId'] ?? ''),
            supplierId: isset($payload['supplierId']) ? (string) $payload['supplierId'] : null,
            createdAt: (new DateTimeImmutable('@'.(int) ($payload['createdAt'] ?? 0)))->setTimezone($utc),
            expiresAt: $expiresAt,
            maxExpiresAt: (new DateTimeImmutable('@'.(int) ($payload['maxExpiresAt'] ?? 0)))->setTimezone($utc),
        );
    }
}
