<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

/**
 * Результат KPI-04 — no-show rate.
 */
final readonly class NoShowRateResult
{
    public function __construct(
        public int $noShowCount,
        public int $totalCount,
        public ?float $percent,
        public int $cancelledCount,
    ) {
    }

    public static function empty(): self
    {
        return new self(noShowCount: 0, totalCount: 0, percent: null, cancelledCount: 0);
    }

    public function hasData(): bool
    {
        return $this->totalCount > 0;
    }

    /** @return array<string, float|int|null> */
    public function toArray(): array
    {
        return [
            'noShowCount' => $this->noShowCount,
            'totalCount' => $this->totalCount,
            'noShowPercent' => Statistics::round($this->percent),
            'cancelledExcluded' => $this->cancelledCount,
        ];
    }
}
