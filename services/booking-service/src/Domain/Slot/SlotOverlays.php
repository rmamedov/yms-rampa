<?php

declare(strict_types=1);

namespace App\Domain\Slot;

/**
 * Матеріалізовані факти, які накладаються на обчислену сітку:
 * блокування, розклади резервів, активні бронювання та холди.
 *
 * Бронювання і холди передаються як множини рядкових ключів слота
 * (SlotKey::toString) — так накладання лишається O(1) на слот.
 */
final readonly class SlotOverlays
{
    /** @var list<SlotBlock> */
    public array $blocks;

    /** @var list<ReservedSlotRule> */
    public array $reservedRules;

    /** @var array<string, true> */
    public array $bookedKeys;

    /** @var array<string, true> */
    public array $heldKeys;

    /**
     * @param list<SlotBlock>        $blocks
     * @param list<ReservedSlotRule> $reservedRules
     * @param list<string>           $bookedKeys ключі слотів з активними бронюваннями
     * @param list<string>           $heldKeys   ключі слотів з активними холдами Redis
     */
    public function __construct(
        array $blocks = [],
        array $reservedRules = [],
        array $bookedKeys = [],
        array $heldKeys = [],
    ) {
        $this->blocks = array_values($blocks);
        $this->reservedRules = array_values($reservedRules);
        $this->bookedKeys = array_fill_keys($bookedKeys, true);
        $this->heldKeys = array_fill_keys($heldKeys, true);
    }

    public static function empty(): self
    {
        return new self();
    }
}
