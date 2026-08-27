<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

/**
 * Результат KPI-02 — % поставок у слот.
 */
final readonly class OnTimeDeliveryResult
{
    public function __construct(
        public int $onTimeCount,
        public int $totalCount,
        public ?float $percent,
        public int $earlyCount,
        public int $lateCount,
        public int $withoutArrivalCount,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            onTimeCount: 0,
            totalCount: 0,
            percent: null,
            earlyCount: 0,
            lateCount: 0,
            withoutArrivalCount: 0,
        );
    }

    public function hasData(): bool
    {
        return $this->totalCount > 0;
    }

    /** @return array<string, float|int|null> */
    public function toArray(): array
    {
        return [
            'onTimeCount' => $this->onTimeCount,
            'totalCount' => $this->totalCount,
            'onTimePercent' => Statistics::round($this->percent),
            'earlyCount' => $this->earlyCount,
            'lateCount' => $this->lateCount,
            'withoutArrivalCount' => $this->withoutArrivalCount,
        ];
    }
}
