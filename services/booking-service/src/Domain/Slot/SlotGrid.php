<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use DateTimeImmutable;

/**
 * Сітка слотів магазину на одну дату разом із параметрами, які потрібні
 * клієнту для валідації та відображення таймерів (GRID-05).
 */
final readonly class SlotGrid
{
    /**
     * @param list<Slot> $slots
     */
    public function __construct(
        public string $storeId,
        public string $date,
        public array $slots,
        public float $maxVehicleWeightTons,
        public int $slotSizeMinutes,
        public int $leadTimeMinutes,
        public DateTimeImmutable $now,
    ) {
    }

    /** @return list<Slot> */
    public function selectableSlots(): array
    {
        return array_values(array_filter($this->slots, static fn (Slot $slot) => $slot->isSelectable()));
    }

    /** @return list<Slot> */
    public function slotsInState(SlotState $state): array
    {
        return array_values(array_filter($this->slots, static fn (Slot $slot) => $slot->state === $state));
    }

    public function countInState(SlotState $state): int
    {
        return \count($this->slotsInState($state));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'storeId' => $this->storeId,
            'date' => $this->date,
            'maxVehicleWeightTons' => $this->maxVehicleWeightTons,
            'slotSizeMinutes' => $this->slotSizeMinutes,
            'leadTimeMinutes' => $this->leadTimeMinutes,
            'now' => $this->now->format('Y-m-d\TH:i:s\Z'),
            'slots' => array_map(static fn (Slot $slot) => $slot->toArray(), $this->slots),
        ];
    }
}
