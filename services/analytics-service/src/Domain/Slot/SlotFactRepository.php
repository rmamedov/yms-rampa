<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use App\Domain\Analytics\AnalyticsQuery;

/**
 * Сховище інвентаря слотів (джерело слото-хвилин для KPI-01).
 */
interface SlotFactRepository
{
    public function save(SlotFact $slot): void;

    /**
     * @param iterable<SlotFact> $slots
     */
    public function saveMany(iterable $slots): void;

    /**
     * @return list<SlotFact>
     */
    public function findByQuery(AnalyticsQuery $query): array;

    public function countAll(): int;
}
