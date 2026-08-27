<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

/**
 * Результат KPI-01 — утилізація рамп у слото-хвилинах.
 */
final readonly class UtilizationResult
{
    public function __construct(
        public float $bookedMinutes,
        public float $availableMinutes,
        public ?float $percent,
        public int $slotsCounted,
    ) {
    }

    public static function empty(): self
    {
        return new self(bookedMinutes: 0.0, availableMinutes: 0.0, percent: null, slotsCounted: 0);
    }

    /** Немає жодного слота, який потрапляє у знаменник (ANL-13). */
    public function hasData(): bool
    {
        return $this->percent !== null;
    }

    /** @return array<string, float|int|null> */
    public function toArray(): array
    {
        return [
            'bookedMinutes' => Statistics::round($this->bookedMinutes),
            'availableMinutes' => Statistics::round($this->availableMinutes),
            'utilizationPercent' => Statistics::round($this->percent),
            'slotsCounted' => $this->slotsCounted,
        ];
    }
}
