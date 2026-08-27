<?php

declare(strict_types=1);

namespace App\Domain\Hold;

use App\Domain\Hold\Exception\HoldExpiredException;
use App\Domain\Hold\Exception\HoldNotOwnedException;
use App\Domain\Hold\Exception\SlotHeldException;
use App\Domain\Slot\SlotKey;
use DateTimeImmutable;

/**
 * Сховище холдів слотів (HOLD-01..HOLD-04).
 *
 * Продакшн-реалізація — Redis (SET NX з TTL); для тестів і dev-режиму
 * є еквівалентна реалізація в памʼяті.
 */
interface SlotHoldStore
{
    /**
     * HOLD-01: атомарне створення холду. На один слот одночасно допускається
     * рівно одна активна hold.
     *
     * @param int $ttlSeconds TTL однієї ітерації (дефолт 300 с)
     * @param int $maxMinutes сумарна стеля тривалості холду (дефолт 15 хв)
     *
     * @throws SlotHeldException якщо слот уже тримає інший користувач
     */
    public function acquire(
        SlotKey $slotKey,
        string $ownerUserId,
        ?string $supplierId,
        DateTimeImmutable $now,
        int $ttlSeconds,
        int $maxMinutes,
    ): SlotHold;

    /**
     * HOLD-02: продовження TTL при активності користувача, але не далі ніж
     * до createdAt + holdMaxMinutes.
     *
     * @throws HoldExpiredException  холд протух або вичерпав ліміт 15 хв
     * @throws HoldNotOwnedException токен не збігається з власником холду
     */
    public function extend(
        SlotKey $slotKey,
        string $holdToken,
        DateTimeImmutable $now,
        int $ttlSeconds,
        int $maxMinutes,
    ): SlotHold;

    /** HOLD-03: явне зняття холду власником (закриття форми). */
    public function release(SlotKey $slotKey, string $holdToken): void;

    /** Поточний холд слота або null, якщо TTL уже протух. */
    public function get(SlotKey $slotKey, DateTimeImmutable $now): ?SlotHold;

    /**
     * Ключі слотів магазину з активними холдами — накладання `held`
     * на сітку (крок 8 GRID-01).
     *
     * @return list<string> рядкові ключі SlotKey::toString()
     */
    public function activeKeys(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array;
}
