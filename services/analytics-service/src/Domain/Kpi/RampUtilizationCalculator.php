<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Domain\Slot\SlotFact;

/**
 * KPI-01 — утилізація рамп.
 *
 * Канонічна формула SRS (розділ 1.2), від якої не допускаються відхилення:
 *
 *     utilization = booked_minutes / available_minutes × 100%
 *
 * де:
 *  - одиниця вимірювання — слото-ХВИЛИНИ, а не кількість слотів;
 *  - зі знаменника available_minutes виключаються слоти станів blocked і past;
 *  - у чисельник booked_minutes входять слото-хвилини слотів стану booked
 *    (held — тимчасовий холд, reserved — незабронений резерв розкладу, тому
 *    в чисельник вони не входять).
 *
 * ANL-01 посилається на цю саму формулу і власної не визначає.
 */
final readonly class RampUtilizationCalculator
{
    /**
     * @param iterable<SlotFact> $slots
     */
    public function calculate(iterable $slots): UtilizationResult
    {
        $bookedMinutes = 0.0;
        $availableMinutes = 0.0;
        $slotsCounted = 0;

        foreach ($slots as $slot) {
            if (!$slot->state->countsInAvailableMinutes()) {
                // blocked і past виключаються зі знаменника (KPI-01)
                continue;
            }

            ++$slotsCounted;
            $minutes = $slot->minutes();
            $availableMinutes += $minutes;

            if ($slot->state->countsInBookedMinutes()) {
                $bookedMinutes += $minutes;
            }
        }

        return new UtilizationResult(
            bookedMinutes: $bookedMinutes,
            availableMinutes: $availableMinutes,
            percent: Statistics::percent($bookedMinutes, $availableMinutes),
            slotsCounted: $slotsCounted,
        );
    }

    /**
     * Розріз утилізації за довільним ключем групування (магазин / рампа / день —
     * розрізи ANL-01, а також місто і мережа за KPI-05).
     *
     * @param iterable<SlotFact>            $slots
     * @param callable(SlotFact): ?string   $keyOf ключ групи; null — слот пропускається
     *
     * @return array<string, UtilizationResult>
     */
    public function calculateGrouped(iterable $slots, callable $keyOf): array
    {
        /** @var array<string, list<SlotFact>> $groups */
        $groups = [];

        foreach ($slots as $slot) {
            $key = $keyOf($slot);
            if ($key === null) {
                continue;
            }
            $groups[$key][] = $slot;
        }

        $result = [];
        foreach ($groups as $key => $groupSlots) {
            $result[$key] = $this->calculate($groupSlots);
        }

        ksort($result);

        return $result;
    }
}
