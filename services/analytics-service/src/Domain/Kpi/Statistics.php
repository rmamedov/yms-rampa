<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

/**
 * Чисті статистичні функції для KPI-03 та ANL-04.
 */
final readonly class Statistics
{
    /**
     * Середнє арифметичне; null для порожнього набору (ANL-13 «Немає даних»).
     *
     * @param list<float> $values
     */
    public static function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Медіана: для парної кількості спостережень — середнє двох центральних.
     * null для порожнього набору.
     *
     * @param list<float> $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Частка у відсотках; null, якщо знаменник нульовий (ділення на нуль
     * неприпустиме — дашборд показує «Немає даних за обраний період», ANL-13).
     */
    public static function percent(float $numerator, float $denominator): ?float
    {
        if ($denominator <= 0.0) {
            return null;
        }

        return $numerator / $denominator * 100;
    }

    /** Округлення показника до 2 знаків, зі збереженням null. */
    public static function round(?float $value, int $precision = 2): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
