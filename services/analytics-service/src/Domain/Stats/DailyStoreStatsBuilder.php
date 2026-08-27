<?php

declare(strict_types=1);

namespace App\Domain\Stats;

use App\Domain\Analytics\PeriodBucket;
use App\Domain\Fact\BookingFact;
use App\Domain\Kpi\KpiSummaryCalculator;
use App\Domain\Slot\SlotFact;

/**
 * Побудова агрегату DailyStoreStats з фактів бронювань та інвентаря слотів.
 *
 * Групування — «магазин × локальна доба Europe/Kyiv». KPI всередині доби
 * рахуються тими самими канонічними калькуляторами KPI-01…KPI-04, тому
 * агрегат ніколи не розходиться з розрахунком по сирих фактах.
 */
final readonly class DailyStoreStatsBuilder
{
    public function __construct(
        private KpiSummaryCalculator $kpiCalculator = new KpiSummaryCalculator(),
    ) {
    }

    /**
     * @param list<BookingFact> $facts
     * @param list<SlotFact>    $slots
     *
     * @return list<DailyStoreStats>
     */
    public function build(array $facts, array $slots, \DateTimeImmutable $recalculatedAt): array
    {
        /** @var array<string, list<BookingFact>> $factGroups */
        $factGroups = [];
        /** @var array<string, list<SlotFact>> $slotGroups */
        $slotGroups = [];
        /** @var array<string, array{storeId: string, city: string, date: string}> $meta */
        $meta = [];

        foreach ($facts as $fact) {
            $date = PeriodBucket::day($fact->slotStart);
            $key = $fact->storeId . ':' . $date;
            $factGroups[$key][] = $fact;
            $meta[$key] ??= ['storeId' => $fact->storeId, 'city' => $fact->city, 'date' => $date];
        }

        foreach ($slots as $slot) {
            $date = PeriodBucket::day($slot->start);
            $key = $slot->storeId . ':' . $date;
            $slotGroups[$key][] = $slot;
            $meta[$key] ??= ['storeId' => $slot->storeId, 'city' => $slot->city, 'date' => $date];
        }

        ksort($meta);

        $result = [];
        foreach ($meta as $key => $info) {
            $kpi = $this->kpiCalculator->calculate($factGroups[$key] ?? [], $slotGroups[$key] ?? []);
            $result[] = DailyStoreStats::fromKpi(
                date: $info['date'],
                storeId: $info['storeId'],
                city: $info['city'],
                kpi: $kpi,
                recalculatedAt: $recalculatedAt,
            );
        }

        return $result;
    }
}
