<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use DateTimeImmutable;

/**
 * Джерело накладань сітки, які зберігаються у store-service:
 * ручні блокування (`slot_blocks`) і розклади резервів (`reserved_slot_rules`).
 *
 * booking-service читає їх копію через API з кешем ≤60 с (DATA-11).
 */
interface SlotOverlayProvider
{
    /**
     * @return list<SlotBlock>
     */
    public function blocksFor(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * @return list<ReservedSlotRule>
     */
    public function reservedRulesFor(string $storeId): array;
}
