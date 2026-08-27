<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Kpi\KpiSummary;

/**
 * Рядок розрізу дашборда: ключ групи (місто, магазин, постачальник, день…)
 * і повний набір KPI цієї групи.
 */
final readonly class BreakdownRow
{
    public function __construct(
        public Dimension $dimension,
        public string $key,
        public KpiSummary $kpi,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension->value,
            'key' => $this->key,
            'kpi' => $this->kpi->toArray(),
        ];
    }
}
