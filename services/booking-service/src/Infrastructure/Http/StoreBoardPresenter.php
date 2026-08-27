<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Store\StoreBoard;
use App\Domain\Booking\Booking;

/**
 * Дошка прибуттів магазину у відповіді API.
 *
 * Форма — `{bookings, now}`, а не голий масив: клієнту потрібен саме
 * СЕРВЕРНИЙ час зрізу. Годинник робочої станції приймальника не збігається
 * з серверним, а на цьому часі тримаються таймери очікування і правила
 * доступності дій (напр. ручний «не приїхав» лише після slotEnd).
 */
final readonly class StoreBoardPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(StoreBoard $board): array
    {
        return [
            'storeId' => $board->storeId,
            'date' => $board->date,
            'now' => $board->now->format('Y-m-d\TH:i:s\Z'),
            'bookings' => array_map(
                static fn (Booking $booking): array => BookingPresenter::toArray(
                    $booking,
                    $board->driverOf($booking),
                ),
                $board->bookings,
            ),
        ];
    }
}
