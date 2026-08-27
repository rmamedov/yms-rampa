<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Slot\SlotFact;
use App\Domain\Slot\SlotFactRepository;

/**
 * Інвентар слотів у памʼяті (KPI-01 без MongoDB).
 */
final class InMemorySlotFactRepository implements SlotFactRepository
{
    /** @var array<string, SlotFact> */
    private array $slots = [];

    /**
     * @param iterable<SlotFact> $slots
     */
    public function __construct(iterable $slots = [])
    {
        $this->saveMany($slots);
    }

    public function save(SlotFact $slot): void
    {
        $this->slots[$slot->slotId] = $slot;
    }

    public function saveMany(iterable $slots): void
    {
        foreach ($slots as $slot) {
            $this->save($slot);
        }
    }

    public function findByQuery(AnalyticsQuery $query): array
    {
        return array_values(array_filter(
            $this->slots,
            static fn (SlotFact $slot): bool => $query->matchesSlot($slot),
        ));
    }

    public function countAll(): int
    {
        return count($this->slots);
    }

    public function clear(): void
    {
        $this->slots = [];
    }
}
