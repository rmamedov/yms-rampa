<?php

declare(strict_types=1);

namespace App\Domain\Slot;

/**
 * Read-модель слота — інвентар слото-хвилин рампи, джерело даних для KPI-01.
 *
 * Час зберігається в UTC; добові/тижневі/місячні розрізи будуються в локальній
 * зоні магазину Europe/Kyiv (див. PeriodBucket).
 *
 * Інвентар синхронізується з генератора сітки booking-service (стан кожного
 * слота на кінець доби), а не будується проєктором подій бронювань: канонічні
 * події не несуть повного стану сітки (blocked/reserved/past зʼявляються
 * без участі бронювань).
 */
final readonly class SlotFact
{
    public function __construct(
        public string $slotId,
        public string $storeId,
        public string $city,
        public string $rampId,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public SlotState $state,
    ) {
    }

    /** Тривалість слота у хвилинах (слото-хвилини KPI-01). */
    public function minutes(): float
    {
        $seconds = $this->end->getTimestamp() - $this->start->getTimestamp();

        return $seconds <= 0 ? 0.0 : $seconds / 60.0;
    }
}
