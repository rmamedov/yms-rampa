<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Exception\InvalidFilterException;
use App\Domain\Fact\BookingFact;
use App\Domain\Fact\BookingFactRepository;
use App\Domain\Kpi\KpiSummary;
use App\Domain\Kpi\KpiSummaryCalculator;
use App\Domain\Kpi\RampUtilizationCalculator;
use App\Domain\Kpi\UtilizationResult;
use App\Domain\Slot\SlotFactRepository;

/**
 * Доменний сервіс дашбордів: віддає KPI за фільтрами ANL-10 і розрізами KPI-05.
 * Не залежить ні від HTTP, ні від MongoDB — працює через доменні репозиторії.
 */
final readonly class AnalyticsDashboard
{
    public function __construct(
        private BookingFactRepository $bookingFacts,
        private SlotFactRepository $slotFacts,
        private KpiSummaryCalculator $kpiCalculator = new KpiSummaryCalculator(),
        private BreakdownCalculator $breakdownCalculator = new BreakdownCalculator(),
        private RampUtilizationCalculator $utilizationCalculator = new RampUtilizationCalculator(),
    ) {
    }

    public function summary(AnalyticsQuery $query): KpiSummary
    {
        return $this->kpiCalculator->calculate(
            $this->bookingFacts->findByQuery($query),
            $this->slotFacts->findByQuery($query),
        );
    }

    /**
     * @return list<BreakdownRow>
     */
    public function breakdown(AnalyticsQuery $query, Dimension $dimension): array
    {
        return $this->breakdownCalculator->calculate(
            $this->bookingFacts->findByQuery($query),
            $this->slotFacts->findByQuery($query),
            $dimension,
        );
    }

    /**
     * ANL-01: утилізація слотів по магазинах у розрізах магазин / рампа / день.
     *
     * @return array<string, UtilizationResult>
     */
    public function utilization(AnalyticsQuery $query, Dimension $dimension): array
    {
        if (!$dimension->supportsSlots()) {
            throw InvalidFilterException::invalidDimension(sprintf(
                'Розріз «%s» не застосовний до утилізації рамп: слот не належить постачальникові.',
                $dimension->value,
            ));
        }

        return $this->utilizationCalculator->calculateGrouped(
            $this->slotFacts->findByQuery($query),
            static fn ($slot): ?string => $dimension->keyOfSlot($slot),
        );
    }

    /**
     * Сирі рядки вибірки (ANL-02, а також джерело CSV-експорту ANL-11).
     *
     * @return list<BookingFact>
     */
    public function bookings(AnalyticsQuery $query): array
    {
        $facts = $this->bookingFacts->findByQuery($query);

        usort(
            $facts,
            static fn (BookingFact $a, BookingFact $b): int => $a->slotStart <=> $b->slotStart
                ?: strcmp($a->bookingId, $b->bookingId),
        );

        return $facts;
    }

    /** ANL-14: мітка часу останнього перерахунку read-моделей. */
    public function recalculatedAt(): ?\DateTimeImmutable
    {
        return $this->bookingFacts->lastUpdatedAt();
    }
}
