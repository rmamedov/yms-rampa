<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Канонічні статуси бронювання (розділ 6.5).
 *
 * Основна лінія: booked → arrived → unloading → completed.
 * Термінальні гілки: cancelled, no_show, rejected.
 * `delayed` статусом НЕ є — це прапорець-атрибут (DLY-01).
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
     * Активні статуси блокують ключ слота (BOOK-07): саме на них
     * побудований частковий унікальний індекс MongoDB (DATA-12).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Booked, self::Arrived, self::Unloading];
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(static fn (self $status) => $status->value, self::active());
    }

    /** Чи блокує це бронювання ключ слота. */
    public function isActive(): bool
    {
        return \in_array($this, self::active(), true);
    }

    /** Термінальний статус — з нього переходів немає. */
    public function isTerminal(): bool
    {
        return !$this->isActive();
    }
}
