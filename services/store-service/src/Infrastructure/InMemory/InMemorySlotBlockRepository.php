<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\SlotBlockRepository;

/**
 * Сховище разових блокувань у памʼяті.
 */
final class InMemorySlotBlockRepository implements SlotBlockRepository
{
    /** @var array<string, SlotBlock> */
    private array $blocks = [];

    public function save(SlotBlock $block): void
    {
        $this->blocks[$block->id] = $block;
    }

    public function find(string $id): ?SlotBlock
    {
        return $this->blocks[$id] ?? null;
    }

    public function findForStore(string $storeId, ?bool $activeOnly = null): array
    {
        $result = array_values(array_filter(
            $this->blocks,
            static fn (SlotBlock $b): bool => $b->storeId === $storeId
                && (null === $activeOnly || $b->isReleased() !== $activeOnly),
        ));

        usort($result, static fn (SlotBlock $a, SlotBlock $b): int => $a->blockFrom <=> $b->blockFrom);

        return $result;
    }

    public function findOverlapping(string $storeId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (SlotBlock $b): bool => $b->storeId === $storeId && $b->overlaps($from, $to),
        ));
    }

    public function delete(string $id): void
    {
        unset($this->blocks[$id]);
    }
}
