<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Application\RouteSheet\RouteSheetService;
use App\Domain\Access\Actor;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\BookingStatus;
use App\Domain\Store\StoreConfigProvider;
use App\Domain\Store\StoreNotFoundException;
use DateTimeImmutable;

/**
 * NOSH-01: cron кожну хвилину переводить у `no_show` бронювання зі статусом
 * `booked`, для яких `now > slotEnd + noShowGraceMinutes` і не було позначки
 * «На місці».
 *
 * Виняток: бронювання з `delayed=true` і ETA у майбутньому виключаються
 * з авто-no_show до `ETA + noShowGraceMinutes`; оновлення ETA зсуває поріг.
 */
final readonly class NoShowSweeper
{
    public function __construct(
        private BookingRepository $bookings,
        private StoreConfigProvider $storeConfigProvider,
        private RouteSheetService $routeSheets,
    ) {
    }

    /**
     * @return list<Booking> бронювання, переведені в no_show цим проходом
     */
    public function sweep(DateTimeImmutable $now): array
    {
        $system = Actor::system();
        $swept = [];

        foreach ($this->bookings->findNoShowCandidates($now) as $booking) {
            if (BookingStatus::Booked !== $booking->status()) {
                continue;
            }

            $grace = $this->graceMinutesFor($booking->storeId);

            if ($now <= $this->thresholdFor($booking, $grace)) {
                continue;
            }

            $this->bookings->save($booking, [
                $booking->markNoShow($system, $now),
                $booking->slotReleasedEvent($now),
            ]);
            $this->routeSheets->syncForBooking($booking);

            $swept[] = $booking;
        }

        return $swept;
    }

    /**
     * Поріг авто-no_show: звичайно це `slotEnd + grace`, а для бронювання
     * з активною позначкою затримки — `ETA + grace`.
     */
    private function thresholdFor(Booking $booking, int $graceMinutes): DateTimeImmutable
    {
        $delay = $booking->delayed();
        $base = $delay->flag && null !== $delay->eta ? $delay->eta : $booking->slotEnd;

        return $base->modify(\sprintf('+%d minutes', $graceMinutes));
    }

    private function graceMinutesFor(string $storeId): int
    {
        try {
            return $this->storeConfigProvider->settingsFor($storeId)->policy->noShowGraceMinutes;
        } catch (StoreNotFoundException) {
            // Магазин міг бути відключений від YMS — залишаємо мережевий дефолт.
            return 30;
        }
    }
}
