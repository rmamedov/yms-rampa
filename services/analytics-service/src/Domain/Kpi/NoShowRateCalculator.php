<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Domain\Booking\BookingStatus;
use App\Domain\Fact\BookingFact;

/**
 * KPI-04 — no-show rate.
 *
 * Канонічна формула SRS (розділ 1.2): частка бронювань зі статусом no_show
 * від УСІХ бронювань, що НЕ були cancelled, за період. Скасовані бронювання
 * повністю виключаються і з чисельника, і зі знаменника.
 *
 * Цільовий поріг дашборда KPI-06: no-show rate ≤ 5%. ANL-03 будує цей самий
 * показник у розрізах постачальник і магазин.
 */
final readonly class NoShowRateCalculator
{
    /**
     * @param iterable<BookingFact> $facts
     */
    public function calculate(iterable $facts): NoShowRateResult
    {
        $noShow = 0;
        $total = 0;
        $cancelled = 0;

        foreach ($facts as $fact) {
            if ($fact->status() === BookingStatus::Cancelled) {
                ++$cancelled;
                continue;
            }

            ++$total;

            if ($fact->status() === BookingStatus::NoShow) {
                ++$noShow;
            }
        }

        return new NoShowRateResult(
            noShowCount: $noShow,
            totalCount: $total,
            percent: Statistics::percent((float) $noShow, (float) $total),
            cancelledCount: $cancelled,
        );
    }
}
