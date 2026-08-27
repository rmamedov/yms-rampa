<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Domain\Fact\BookingFact;
use App\Domain\Slot\SlotFact;

/**
 * Композитний калькулятор: збирає KPI-01…KPI-04 та метрики ANL-02…ANL-05
 * для однієї вибірки фактів бронювань і інвентаря слотів.
 */
final readonly class KpiSummaryCalculator
{
    public function __construct(
        private RampUtilizationCalculator $utilization = new RampUtilizationCalculator(),
        private OnTimeDeliveryCalculator $onTimeDelivery = new OnTimeDeliveryCalculator(),
        private WaitingTimeCalculator $waitingTime = new WaitingTimeCalculator(),
        private NoShowRateCalculator $noShowRate = new NoShowRateCalculator(),
        private UnloadingDurationCalculator $unloadingDuration = new UnloadingDurationCalculator(),
    ) {
    }

    /**
     * @param list<BookingFact> $facts
     * @param list<SlotFact>    $slots
     */
    public function calculate(array $facts, array $slots): KpiSummary
    {
        return new KpiSummary(
            utilization: $this->utilization->calculate($slots),
            onTimeDelivery: $this->onTimeDelivery->calculate($facts),
            waitingTime: $this->waitingTime->calculate($facts),
            noShowRate: $this->noShowRate->calculate($facts),
            unloadingTime: $this->unloadingDuration->calculate($facts),
            averageSlotMinutes: $this->unloadingDuration->averageSlotMinutes($facts),
            counters: BookingCounters::fromFacts($facts),
        );
    }
}
