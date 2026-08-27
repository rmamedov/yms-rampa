<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Domain\Fact\BookingFact;

/**
 * KPI-03 — середній та медіанний час очікування машини.
 *
 * Канонічна формула SRS (розділ 1.2): інтервал від події arrived
 * до переходу в unloading. У вибірку потрапляють лише бронювання,
 * де зафіксовані обидві події; суперечливі пари (unloading раніше за arrived)
 * відкидаються як аномалія даних.
 *
 * Цільовий поріг дашборда KPI-06: медіанне очікування ≤ 20 хв.
 */
final readonly class WaitingTimeCalculator
{
    /**
     * @param iterable<BookingFact> $facts
     */
    public function calculate(iterable $facts): DurationStatsResult
    {
        /** @var list<float> $samples */
        $samples = [];

        foreach ($facts as $fact) {
            $minutes = $fact->waitingMinutes();
            if ($minutes !== null) {
                $samples[] = $minutes;
            }
        }

        return DurationStatsResult::fromSamples($samples);
    }
}
