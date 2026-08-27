<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Domain\Fact\BookingFact;

/**
 * KPI-02 — % поставок у слот.
 *
 * Канонічна формула SRS (розділ 1.2):
 * частка бронювань, для яких подія arrived зафіксована в межах
 * `[slotStart − 15 хв; slotEnd]`, від УСІХ бронювань зі статусами
 * completed, unloading, arrived за період.
 *
 * Межі інтервалу включні з обох боків. Бронювання зі статусом із знаменника,
 * але без зафіксованого arrivedAt, залишається у знаменнику і не потрапляє
 * в чисельник (аномалія даних, яку показуємо окремим лічильником).
 */
final readonly class OnTimeDeliveryCalculator
{
    /** Допуск раннього прибуття у хвилинах, зафіксований формулою KPI-02. */
    public const EARLY_TOLERANCE_MINUTES = 15;

    /**
     * @param iterable<BookingFact> $facts
     */
    public function calculate(iterable $facts): OnTimeDeliveryResult
    {
        $onTime = 0;
        $total = 0;
        $early = 0;
        $late = 0;
        $withoutArrival = 0;

        foreach ($facts as $fact) {
            if (!$fact->status()->countsForOnTimeDelivery()) {
                continue;
            }

            ++$total;
            $arrivedAt = $fact->arrivedAt();

            if ($arrivedAt === null) {
                ++$withoutArrival;
                continue;
            }

            $windowStart = $fact->slotStart->modify('-' . self::EARLY_TOLERANCE_MINUTES . ' minutes');

            if ($arrivedAt < $windowStart) {
                ++$early;
                continue;
            }

            if ($arrivedAt > $fact->slotEnd) {
                ++$late;
                continue;
            }

            ++$onTime;
        }

        return new OnTimeDeliveryResult(
            onTimeCount: $onTime,
            totalCount: $total,
            percent: Statistics::percent((float) $onTime, (float) $total),
            earlyCount: $early,
            lateCount: $late,
            withoutArrivalCount: $withoutArrival,
        );
    }
}
