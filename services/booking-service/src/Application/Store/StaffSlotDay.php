<?php

declare(strict_types=1);

namespace App\Application\Store;

use App\Domain\Slot\Slot;
use App\Domain\Slot\SlotGrid;

/**
 * Сітка слотів однієї доби ОЧИМА ПЕРСОНАЛУ.
 *
 * Відрізняється від сітки постачальника двома речами:
 *   - зайнятий слот несе `bookingId`, тож із клітинки сітки відкривається
 *     картка прибуття (постачальнику чуже бронювання не належить);
 *   - чужі резерви не приховуються (GRID-04 обмежує саме постачальника):
 *     персонал бачить, за ким закріплено слот, інакше «зайнято без причини».
 */
final readonly class StaffSlotDay
{
    /**
     * @param array<string, string> $bookingIdBySlotKey ключ — SlotKey::toString()
     */
    public function __construct(
        public string $dateKey,
        public SlotGrid $grid,
        public array $bookingIdBySlotKey,
    ) {
    }

    public function bookingIdOf(Slot $slot): ?string
    {
        return $this->bookingIdBySlotKey[$slot->key->toString()] ?? null;
    }
}
