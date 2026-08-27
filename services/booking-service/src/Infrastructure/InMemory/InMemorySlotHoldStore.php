<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Hold\Exception\HoldExpiredException;
use App\Domain\Hold\Exception\HoldNotOwnedException;
use App\Domain\Hold\Exception\SlotHeldException;
use App\Domain\Hold\SlotHold;
use App\Domain\Hold\SlotHoldStore;
use App\Domain\Slot\SlotKey;
use DateTimeImmutable;

/**
 * Холди слотів у памʼяті — повний функціональний еквівалент Redis-реалізації
 * (HOLD-01..HOLD-03), щоб юніт-тести працювали без Redis.
 */
final class InMemorySlotHoldStore implements SlotHoldStore
{
    /** @var array<string, SlotHold> */
    private array $holds = [];

    public function acquire(
        SlotKey $slotKey,
        string $ownerUserId,
        ?string $supplierId,
        DateTimeImmutable $now,
        int $ttlSeconds,
        int $maxMinutes,
    ): SlotHold {
        // HOLD-01: аналог атомарного SET NX — на слот рівно одна активна hold.
        if (null !== $this->get($slotKey, $now)) {
            throw new SlotHeldException();
        }

        $maxExpiresAt = $now->modify(\sprintf('+%d minutes', $maxMinutes));
        $expiresAt = $now->modify(\sprintf('+%d seconds', $ttlSeconds));

        $hold = new SlotHold(
            slotKey: $slotKey,
            holdToken: bin2hex(random_bytes(16)),
            ownerUserId: $ownerUserId,
            supplierId: $supplierId,
            createdAt: $now,
            expiresAt: min($expiresAt, $maxExpiresAt),
            maxExpiresAt: $maxExpiresAt,
        );

        $this->holds[$slotKey->toString()] = $hold;

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

        // HOLD-02: сумарна тривалість однієї hold не може перевищувати holdMaxMinutes.
        if ($hold->isExhaustedAt($now)) {
            unset($this->holds[$slotKey->toString()]);

            throw new HoldExpiredException();
        }

        $extended = new SlotHold(
            slotKey: $hold->slotKey,
            holdToken: $hold->holdToken,
            ownerUserId: $hold->ownerUserId,
            supplierId: $hold->supplierId,
            createdAt: $hold->createdAt,
            expiresAt: min($now->modify(\sprintf('+%d seconds', $ttlSeconds)), $hold->maxExpiresAt),
            maxExpiresAt: $hold->maxExpiresAt,
        );

        $this->holds[$slotKey->toString()] = $extended;

        return $extended;
    }

    public function release(SlotKey $slotKey, string $holdToken): void
    {
        $hold = $this->holds[$slotKey->toString()] ?? null;

        if (null === $hold) {
            return;
        }

        if (!$hold->isOwnedBy($holdToken)) {
            throw new HoldNotOwnedException();
        }

        unset($this->holds[$slotKey->toString()]);
    }

    public function get(SlotKey $slotKey, DateTimeImmutable $now): ?SlotHold
    {
        $hold = $this->holds[$slotKey->toString()] ?? null;

        if (null === $hold) {
            return null;
        }

        // Протухання TTL повертає слот у available без додаткових дій (HOLD-03).
        if ($hold->isExpiredAt($now)) {
            unset($this->holds[$slotKey->toString()]);

            return null;
        }

        return $hold;
    }

    public function activeKeys(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        $keys = [];

        foreach ($this->holds as $key => $hold) {
            if ($hold->slotKey->storeId !== $storeId) {
                continue;
            }

            if ($hold->isExpiredAt($now)) {
                unset($this->holds[$key]);

                continue;
            }

            if ($hold->slotKey->slotStart >= $from && $hold->slotKey->slotStart < $to) {
                $keys[] = $key;
            }
        }

        return array_values($keys);
    }

    public function clear(): void
    {
        $this->holds = [];
    }
}
