<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\BreakdownRow;
use App\Domain\Booking\BookingStatus;
use App\Domain\Fact\BookingFact;
use App\Domain\Kpi\OnTimeDeliveryCalculator;
use App\Domain\Kpi\Statistics;

/**
 * Формування CSV-вибірок дашбордів (ANL-11): експорт відтворює рівно ту
 * вибірку, що на екрані — і рядки бронювань, і зведення розрізу.
 */
final readonly class AnalyticsCsvView
{
    public function __construct(private CsvExporter $exporter = new CsvExporter())
    {
    }

    /**
     * @param list<BookingFact> $facts
     */
    public function bookings(AnalyticsQuery $query, array $facts): string
    {
        $headers = [
            'bookingId', 'Магазин', 'Місто', 'Постачальник', 'Рампа',
            'Початок слоту (UTC)', 'Кінець слоту (UTC)', 'Тип', 'Статус',
            'Прибув', 'Початок розвантаження', 'Завершено',
            'Очікування, хв', 'Розвантаження, хв', 'У слот',
            'Палет заплановано', 'Палет розвантажено', 'Часткове розвантаження',
            'Затримка', 'Причина затримки', 'Причина відмови',
        ];

        $rows = [];
        foreach ($facts as $fact) {
            $rows[] = [
                $fact->bookingId,
                $fact->storeId,
                $fact->city,
                $fact->supplierId,
                $fact->rampId(),
                $fact->slotStart,
                $fact->slotEnd,
                $fact->type->label(),
                $fact->status()->label(),
                $fact->arrivedAt(),
                $fact->unloadingStartedAt(),
                $fact->completedAt(),
                Statistics::round($fact->waitingMinutes()),
                Statistics::round($fact->unloadingMinutes()),
                $this->onTimeLabel($fact),
                $fact->palletsCount,
                $fact->unloadedPalletsCount(),
                $fact->isPartialUnload(),
                $fact->isDelayed(),
                $fact->delayReason(),
                $fact->rejectedReason()?->label(),
            ];
        }

        return $this->exporter->export($query->describe(), $headers, $rows);
    }

    /**
     * @param list<BreakdownRow> $breakdown
     */
    public function breakdown(AnalyticsQuery $query, array $breakdown): string
    {
        $headers = [
            'Розріз', 'Ключ', 'Бронювань',
            'KPI-01 утилізація, %', 'Заброньовано, хв', 'Доступно, хв',
            'KPI-02 у слот, %', 'KPI-03 очікування середнє, хв', 'KPI-03 очікування медіана, хв',
            'KPI-04 no-show, %', 'Завершено', 'Скасовано', 'Не прибув', 'Відмовлено',
            'Walk-in', 'Заплановані', 'Затримок',
        ];

        $rows = [];
        foreach ($breakdown as $row) {
            $kpi = $row->kpi;
            $rows[] = [
                $row->dimension->label(),
                $row->key,
                $kpi->counters->total,
                Statistics::round($kpi->utilization->percent),
                Statistics::round($kpi->utilization->bookedMinutes),
                Statistics::round($kpi->utilization->availableMinutes),
                Statistics::round($kpi->onTimeDelivery->percent),
                Statistics::round($kpi->waitingTime->averageMinutes),
                Statistics::round($kpi->waitingTime->medianMinutes),
                Statistics::round($kpi->noShowRate->percent),
                $kpi->counters->status(BookingStatus::Completed),
                $kpi->counters->status(BookingStatus::Cancelled),
                $kpi->counters->status(BookingStatus::NoShow),
                $kpi->counters->status(BookingStatus::Rejected),
                $kpi->counters->byType['walk_in'] ?? 0,
                $kpi->counters->byType['scheduled'] ?? 0,
                $kpi->counters->delayedCount,
            ];
        }

        return $this->exporter->export($query->describe(), $headers, $rows);
    }

    /** Позначка попадання в слот за формулою KPI-02. */
    private function onTimeLabel(BookingFact $fact): string
    {
        if (!$fact->status()->countsForOnTimeDelivery()) {
            return 'н/д';
        }

        $arrivedAt = $fact->arrivedAt();
        if ($arrivedAt === null) {
            return 'н/д';
        }

        $windowStart = $fact->slotStart->modify(
            '-' . OnTimeDeliveryCalculator::EARLY_TOLERANCE_MINUTES . ' minutes',
        );

        return $arrivedAt >= $windowStart && $arrivedAt <= $fact->slotEnd ? 'так' : 'ні';
    }
}
