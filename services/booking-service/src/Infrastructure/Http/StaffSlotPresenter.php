<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Store\StaffSlotDay;
use App\Domain\Slot\Slot;

/**
 * Сітка слотів у відповіді контуру магазину.
 *
 * Відповідь — ПЛОСКИЙ масив слотів (а не обгортка з метаданими, як у контурі
 * постачальника): екран магазину читає сітку разом із конфігурацією філії,
 * тож дублювати slotSizeMinutes і ліміт тоннажу в кожній вибірці немає сенсу.
 *
 * До полів слота додається `bookingId` — саме він перетворює клітинку сітки
 * на посилання на картку прибуття. Чужі резерви лишаються видимими:
 * приховування (GRID-04) стосується постачальника, а не персоналу.
 */
final readonly class StaffSlotPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function slots(StaffSlotDay $day): array
    {
        return array_map(
            static fn (Slot $slot): array => $slot->toArray() + [
                'bookingId' => $day->bookingIdOf($slot),
            ],
            $day->grid->slots,
        );
    }

    /**
     * Тиждень: доба з ключем локальної дати — рівно те, чим підписані
     * колонки екрана «Розклад тижня».
     *
     * @param list<StaffSlotDay> $days
     *
     * @return list<array<string, mixed>>
     */
    public static function week(array $days): array
    {
        return array_map(
            static fn (StaffSlotDay $day): array => [
                'dateKey' => $day->dateKey,
                'slots' => self::slots($day),
            ],
            $days,
        );
    }
}
