<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Fact\BookingFact;
use App\Domain\Kpi\KpiSummaryCalculator;
use App\Domain\Slot\SlotFact;

/**
 * KPI-05 — усі KPI в розрізах: мережа / місто / магазин / рампа /
 * постачальник / день-тиждень-місяць, а також окремі розрізи за типом
 * бронювання (walk_in vs scheduled) і за причинами відмов.
 *
 * Для розрізів, яких немає в інвентарі слотів (постачальник, тип, причина
 * відмови), KPI-01 повертається порожнім: слот не належить постачальникові.
 */
final readonly class BreakdownCalculator
{
    public function __construct(
        private KpiSummaryCalculator $kpiCalculator = new KpiSummaryCalculator(),
    ) {
    }

    /**
     * @param list<BookingFact> $facts
     * @param list<SlotFact>    $slots
     *
     * @return list<BreakdownRow> відсортовано за ключем групи
     */
    public function calculate(array $facts, array $slots, Dimension $dimension): array
    {
        /** @var array<string, list<BookingFact>> $factGroups */
        $factGroups = [];
        foreach ($facts as $fact) {
            if ($dimension === Dimension::RejectionReason && $fact->rejectedReason() === null) {
                // розріз за причинами відмов охоплює лише бронювання зі статусом rejected
                continue;
            }
            $factGroups[$dimension->keyOf($fact)][] = $fact;
        }

        /** @var array<string, list<SlotFact>> $slotGroups */
        $slotGroups = [];
        if ($dimension->supportsSlots()) {
            foreach ($slots as $slot) {
                $key = $dimension->keyOfSlot($slot);
                if ($key !== null) {
                    $slotGroups[$key][] = $slot;
                }
            }
        }

        $keys = array_values(array_unique([...array_keys($factGroups), ...array_keys($slotGroups)]));
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = new BreakdownRow(
                dimension: $dimension,
                key: $key,
                kpi: $this->kpiCalculator->calculate($factGroups[$key] ?? [], $slotGroups[$key] ?? []),
            );
        }

        return $rows;
    }
}
