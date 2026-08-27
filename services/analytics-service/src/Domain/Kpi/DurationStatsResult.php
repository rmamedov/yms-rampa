<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

/**
 * Середнє і медіана інтервалу у хвилинах — результат KPI-03 (час очікування)
 * та ANL-04 (час розвантаження).
 */
final readonly class DurationStatsResult
{
    /**
     * @param list<float> $samples вибірка інтервалів у хвилинах
     */
    public function __construct(
        public ?float $averageMinutes,
        public ?float $medianMinutes,
        public int $sampleSize,
        public array $samples = [],
    ) {
    }

    /**
     * @param list<float> $samples
     */
    public static function fromSamples(array $samples): self
    {
        return new self(
            averageMinutes: Statistics::average($samples),
            medianMinutes: Statistics::median($samples),
            sampleSize: count($samples),
            samples: $samples,
        );
    }

    public static function empty(): self
    {
        return self::fromSamples([]);
    }

    public function hasData(): bool
    {
        return $this->sampleSize > 0;
    }

    /** @return array<string, float|int|null> */
    public function toArray(): array
    {
        return [
            'averageMinutes' => Statistics::round($this->averageMinutes),
            'medianMinutes' => Statistics::round($this->medianMinutes),
            'sampleSize' => $this->sampleSize,
        ];
    }
}
