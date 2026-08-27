<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

/**
 * Зведення KPI-01…KPI-04 та супутніх метрик ANL-02…ANL-05 для однієї вибірки.
 *
 * Довідкові пороги KPI-06 фіксуються тут же, щоб дашборд міг підсвітити
 * відхилення; вони не є умовою приймання ПЗ.
 */
final readonly class KpiSummary
{
    public const TARGET_UTILIZATION_PERCENT = 60.0;
    public const TARGET_ON_TIME_PERCENT = 85.0;
    public const TARGET_MEDIAN_WAITING_MINUTES = 20.0;
    public const TARGET_NO_SHOW_PERCENT = 5.0;

    public function __construct(
        public UtilizationResult $utilization,
        public OnTimeDeliveryResult $onTimeDelivery,
        public DurationStatsResult $waitingTime,
        public NoShowRateResult $noShowRate,
        public DurationStatsResult $unloadingTime,
        public ?float $averageSlotMinutes,
        public BookingCounters $counters,
    ) {
    }

    /** ANL-13: чи є взагалі дані за обраним фільтром. */
    public function hasData(): bool
    {
        return $this->counters->total > 0 || $this->utilization->slotsCounted > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kpi01_rampUtilization' => $this->utilization->toArray(),
            'kpi02_onTimeDelivery' => $this->onTimeDelivery->toArray(),
            'kpi03_waitingTime' => $this->waitingTime->toArray(),
            'kpi04_noShowRate' => $this->noShowRate->toArray(),
            'anl04_unloadingTime' => $this->unloadingTime->toArray() + [
                'averageSlotMinutes' => Statistics::round($this->averageSlotMinutes),
            ],
            'counters' => $this->counters->toArray(),
            'targets' => [
                'utilizationPercent' => self::TARGET_UTILIZATION_PERCENT,
                'onTimePercent' => self::TARGET_ON_TIME_PERCENT,
                'medianWaitingMinutes' => self::TARGET_MEDIAN_WAITING_MINUTES,
                'noShowPercent' => self::TARGET_NO_SHOW_PERCENT,
            ],
        ];
    }
}
