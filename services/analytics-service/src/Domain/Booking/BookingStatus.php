<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Канонічні статуси бронювання (розділ 1.5 SRS).
 *
 * Основна лінія: booked → arrived → unloading → completed.
 * Гілки-відгалуження: cancelled, no_show, rejected.
 */
enum BookingStatus: string
{
    case Booked = 'booked';
    case Arrived = 'arrived';
    case Unloading = 'unloading';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Rejected = 'rejected';

    /**
     * Ранг статусу для захисту від подій, що приходять не в порядку публікації.
     * Статус read-моделі рухається лише вперед (див. PROJ-idempotency у EventProjector).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Booked => 10,
            self::Arrived => 20,
            self::Unloading => 30,
            self::Completed, self::Cancelled, self::NoShow, self::Rejected => 40,
        };
    }

    /** Термінальний статус далі не змінюється. */
    public function isTerminal(): bool
    {
        return $this->rank() === 40;
    }

    /**
     * Статуси, що беруть участь у знаменнику KPI-02 (% поставок у слот):
     * усі бронювання зі статусами completed, unloading, arrived.
     */
    public function countsForOnTimeDelivery(): bool
    {
        return match ($this) {
            self::Completed, self::Unloading, self::Arrived => true,
            default => false,
        };
    }

    /** Людиночитана назва статусу (українською) для експорту та дашбордів. */
    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Заброньовано',
            self::Arrived => 'Прибув',
            self::Unloading => 'Розвантаження',
            self::Completed => 'Завершено',
            self::Cancelled => 'Скасовано',
            self::NoShow => 'Не прибув',
            self::Rejected => 'Відмовлено в прийомі',
        };
    }
}
