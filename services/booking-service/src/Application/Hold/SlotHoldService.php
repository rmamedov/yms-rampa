<?php

declare(strict_types=1);

namespace App\Application\Hold;

use App\Application\Slot\SlotGridService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Booking\Exception\SlotNotAvailableException;
use App\Domain\Booking\Exception\SlotReservedException;
use App\Domain\Hold\Exception\SlotHeldException;
use App\Domain\Hold\SlotHold;
use App\Domain\Hold\SlotHoldStore;
use App\Domain\Slot\SlotKey;
use App\Domain\Slot\SlotState;
use DateTimeImmutable;

/**
 * Механіка холдів слота (HOLD-01..HOLD-04).
 *
 * Холд — оптимістичний UX-механізм: він не є гарантією унікальності,
 * фінальну гарантію дає частковий унікальний індекс MongoDB (BOOK-08).
 */
final readonly class SlotHoldService
{
    public function __construct(
        private SlotGridService $grid,
        private SlotHoldStore $holds,
    ) {
    }

    /**
     * HOLD-01: створити холд при відкритті форми бронювання.
     */
    public function hold(Actor $actor, SlotKey $slotKey, DateTimeImmutable $now): SlotHold
    {
        if (!$actor->role->isSupplier()) {
            throw new AccessDeniedException('Тримати слот може лише користувач кабінету постачальника');
        }

        $settings = $this->grid->settingsFor($slotKey->storeId, $actor);
        $date = SlotGridService::localDate($slotKey->slotStart);
        $grid = $this->grid->build($settings, $date, $actor->supplierId, $now);
        $slot = $this->grid->findSlot($grid, $slotKey);

        if (null === $slot) {
            throw SlotNotAvailableException::outsideGrid();
        }

        match ($slot->state) {
            SlotState::Available => null,
            SlotState::Held => throw new SlotHeldException(),
            SlotState::Booked => throw new SlotAlreadyBookedException($slotKey),
            SlotState::Reserved => throw new SlotReservedException(),
            SlotState::Past => throw SlotNotAvailableException::leadTime($settings->config->leadTimeMinutes),
            SlotState::Blocked => throw new SlotNotAvailableException(SlotState::Blocked, 'Слот заблоковано магазином'),
        };

        return $this->holds->acquire(
            slotKey: $slotKey,
            ownerUserId: $actor->userId,
            supplierId: $actor->supplierId,
            now: $now,
            ttlSeconds: $settings->policy->holdTtlSeconds,
            maxMinutes: $settings->policy->holdMaxMinutes,
        );
    }

    /**
     * HOLD-02: продовження TTL при активності користувача (heartbeat раз на 60 с),
     * але не довше сумарних holdMaxMinutes.
     */
    public function extend(Actor $actor, SlotKey $slotKey, string $holdToken, DateTimeImmutable $now): SlotHold
    {
        $settings = $this->grid->settingsFor($slotKey->storeId, $actor);

        return $this->holds->extend(
            slotKey: $slotKey,
            holdToken: $holdToken,
            now: $now,
            ttlSeconds: $settings->policy->holdTtlSeconds,
            maxMinutes: $settings->policy->holdMaxMinutes,
        );
    }

    /** HOLD-03: закриття форми або явне скасування знімає холд негайно. */
    public function release(SlotKey $slotKey, string $holdToken): void
    {
        $this->holds->release($slotKey, $holdToken);
    }
}
