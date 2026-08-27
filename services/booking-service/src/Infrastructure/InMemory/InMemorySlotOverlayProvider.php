<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Slot\ReservedSlotRule;
use App\Domain\Slot\SlotBlock;
use App\Domain\Slot\SlotOverlayProvider;
use DateTimeImmutable;

/**
 * Блокування і розклади резервів у памʼяті. У проді ці дані приходять
 * зі store-service (`slot_blocks`, `reserved_slot_rules`) з кешем ≤60 с.
 */
final class InMemorySlotOverlayProvider implements SlotOverlayProvider
{
    /** @var list<SlotBlock> */
    private array $blocks = [];

    /** @var array<string, list<ReservedSlotRule>> */
    private array $reservedRules = [];

    /**
     * @param list<SlotBlock> $blocks
     */
    public function __construct(array $blocks = [])
    {
        $this->blocks = array_values($blocks);
    }

    public function addBlock(SlotBlock $block): void
    {
        $this->blocks[] = $block;
    }

    public function addReservedRule(string $storeId, ReservedSlotRule $rule): void
    {
        $this->reservedRules[$storeId][] = $rule;
    }

    public function blocksFor(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (SlotBlock $block) => $block->storeId === $storeId && $block->from < $to && $block->to > $from,
        ));
    }

    public function reservedRulesFor(string $storeId): array
    {
        return $this->reservedRules[$storeId] ?? [];
    }

    public function clear(): void
    {
        $this->blocks = [];
        $this->reservedRules = [];
    }
}
