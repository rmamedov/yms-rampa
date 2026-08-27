<?php

declare(strict_types=1);

namespace App\Domain\Stats;

use App\Domain\Kpi\KpiSummary;
use App\Domain\Kpi\Statistics;

/**
 * Агрегована read-модель «магазин × доба» — основа дашбордів ANL-01…ANL-05
 * і швидкої віддачі KPI без перерахунку по сирих фактах.
 *
 * Доба — локальна доба магазину (Europe/Kyiv), ключ формату Y-m-d.
 */
final readonly class DailyStoreStats
{
    public function __construct(
        public string $date,
        public string $storeId,
        public string $city,
        public int $bookingsTotal,
        public int $completedCount,
        public int $cancelledCount,
        public int $noShowCount,
        public int $rejectedCount,
        public int $walkInCount,
        public int $scheduledCount,
        public int $delayedCount,
        public int $plannedPallets,
        public int $unloadedPallets,
        public float $bookedMinutes,
        public float $availableMinutes,
        public ?float $utilizationPercent,
        public ?float $onTimePercent,
        public ?float $waitingAverageMinutes,
        public ?float $waitingMedianMinutes,
        public ?float $unloadingAverageMinutes,
        public ?float $unloadingMedianMinutes,
        public ?float $noShowPercent,
        public \DateTimeImmutable $recalculatedAt,
    ) {
    }

    public static function fromKpi(
        string $date,
        string $storeId,
        string $city,
        KpiSummary $kpi,
        \DateTimeImmutable $recalculatedAt,
    ): self {
        $counters = $kpi->counters;

        return new self(
            date: $date,
            storeId: $storeId,
            city: $city,
            bookingsTotal: $counters->total,
            completedCount: $counters->byStatus['completed'] ?? 0,
            cancelledCount: $counters->byStatus['cancelled'] ?? 0,
            noShowCount: $counters->byStatus['no_show'] ?? 0,
            rejectedCount: $counters->byStatus['rejected'] ?? 0,
            walkInCount: $counters->byType['walk_in'] ?? 0,
            scheduledCount: $counters->byType['scheduled'] ?? 0,
            delayedCount: $counters->delayedCount,
            plannedPallets: $counters->plannedPallets,
            unloadedPallets: $counters->unloadedPallets,
            bookedMinutes: $kpi->utilization->bookedMinutes,
            availableMinutes: $kpi->utilization->availableMinutes,
            utilizationPercent: $kpi->utilization->percent,
            onTimePercent: $kpi->onTimeDelivery->percent,
            waitingAverageMinutes: $kpi->waitingTime->averageMinutes,
            waitingMedianMinutes: $kpi->waitingTime->medianMinutes,
            unloadingAverageMinutes: $kpi->unloadingTime->averageMinutes,
            unloadingMedianMinutes: $kpi->unloadingTime->medianMinutes,
            noShowPercent: $kpi->noShowRate->percent,
            recalculatedAt: $recalculatedAt,
        );
    }

    public function id(): string
    {
        return $this->storeId . ':' . $this->date;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'storeId' => $this->storeId,
            'city' => $this->city,
            'bookingsTotal' => $this->bookingsTotal,
            'completedCount' => $this->completedCount,
            'cancelledCount' => $this->cancelledCount,
            'noShowCount' => $this->noShowCount,
            'rejectedCount' => $this->rejectedCount,
            'walkInCount' => $this->walkInCount,
            'scheduledCount' => $this->scheduledCount,
            'delayedCount' => $this->delayedCount,
            'plannedPallets' => $this->plannedPallets,
            'unloadedPallets' => $this->unloadedPallets,
            'bookedMinutes' => Statistics::round($this->bookedMinutes),
            'availableMinutes' => Statistics::round($this->availableMinutes),
            'utilizationPercent' => Statistics::round($this->utilizationPercent),
            'onTimePercent' => Statistics::round($this->onTimePercent),
            'waitingAverageMinutes' => Statistics::round($this->waitingAverageMinutes),
            'waitingMedianMinutes' => Statistics::round($this->waitingMedianMinutes),
            'unloadingAverageMinutes' => Statistics::round($this->unloadingAverageMinutes),
            'unloadingMedianMinutes' => Statistics::round($this->unloadingMedianMinutes),
            'noShowPercent' => Statistics::round($this->noShowPercent),
            'recalculatedAt' => $this->recalculatedAt->format(\DATE_ATOM),
        ];
    }
}
