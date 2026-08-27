<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Domain\Fact\BookingFact;

/**
 * ANL-04 — середній час розвантаження: середнє і медіана інтервалу
 * від переходу в unloading до completed, з можливістю порівняння
 * з розміром слоту.
 */
final readonly class UnloadingDurationCalculator
{
    /**
     * @param iterable<BookingFact> $facts
     */
    public function calculate(iterable $facts): DurationStatsResult
    {
        /** @var list<float> $samples */
        $samples = [];

        foreach ($facts as $fact) {
            $minutes = $fact->unloadingMinutes();
            if ($minutes !== null) {
                $samples[] = $minutes;
            }
        }

        return DurationStatsResult::fromSamples($samples);
    }

    /**
     * Середня тривалість слота у вибірці — база порівняння «розвантаження
     * проти розміру слоту» (ANL-04).
     *
     * @param iterable<BookingFact> $facts
     */
    public function averageSlotMinutes(iterable $facts): ?float
    {
        /** @var list<float> $slotMinutes */
        $slotMinutes = [];

        foreach ($facts as $fact) {
            $slotMinutes[] = $fact->slotMinutes();
        }

        return Statistics::average($slotMinutes);
    }
}
