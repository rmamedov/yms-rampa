<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Access\Actor;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\DelayReason;
use DateTimeImmutable;

/**
 * Дії контуру водія над бронюванням (розділ 8, блок DRV).
 *
 * Водій має РІВНО ТРИ повноваження і жодного більше:
 *   1) відмітити прибуття «На місці» (ST-01, booked → arrived);
 *   2) повідомити про затримку з причиною та новим ETA (DLY-01);
 *   3) дописати номер замовлення, якщо його не вказав постачальник.
 *
 * Переходи в unloading/completed/rejected/no_show, скасування бронювання
 * і реєстрація walk-in водієві недоступні: сюди вони просто не заведені,
 * а машина станів агрегату відхиляє їх незалежно від цього сервісу.
 *
 * Правила переходів НЕ дублюються — вони живуть в агрегаті Booking, і кожна
 * дія проходить через BookingLifecycleService так само, як дії магазину.
 * Додається лише перевірка належності точки маршрутному листу водія.
 */
final readonly class DriverBookingService
{
    public function __construct(
        private BookingLifecycleService $lifecycle,
        private BookingRepository $bookings,
    ) {
    }

    /**
     * DRV + ST-01: водій тисне «На місці» → booked → arrived, публікується
     * подія BookingArrived, і машина зʼявляється в черзі магазину.
     *
     * Відмітити прибуття можуть і водій, і магазин — хто перший. Тому повторне
     * натискання на вже позначеному бронюванні НЕ помилка: повертається
     * поточний стан без другого переходу і без другої події.
     */
    public function markArrived(Actor $actor, string $bookingId, DateTimeImmutable $now): Booking
    {
        $booking = $this->ownPoint($actor, $bookingId);

        if (BookingStatus::Arrived === $booking->status()) {
            return $booking;
        }

        return $this->lifecycle->markArrived($actor, $bookingId, $now);
    }

    /**
     * DRV + DLY-01: водій повідомляє про затримку своєї точки — причина
     * з довідника і новий орієнтовний час прибуття. Статус не змінюється.
     */
    public function reportDelay(
        Actor $actor,
        string $bookingId,
        DelayReason $reason,
        DateTimeImmutable $eta,
        DateTimeImmutable $now,
        ?string $comment = null,
    ): Booking {
        $this->ownPoint($actor, $bookingId);

        return $this->lifecycle->setDelay($actor, $bookingId, $reason, $eta, $now, $comment);
    }

    /**
     * DRV: водій дописує або змінює orderId власної точки (розділ 6.4).
     * Інші поля бронювання цим шляхом недосяжні.
     */
    public function updateOrderId(
        Actor $actor,
        string $bookingId,
        ?string $orderId,
        DateTimeImmutable $now,
    ): Booking {
        $booking = $this->ownPoint($actor, $bookingId);
        $booking->setOrderIdByDriver($actor, $orderId, $now);

        // Зміна orderId не змінює статус, тож доменних подій не породжує (як EDIT-04).
        $this->bookings->save($booking, []);

        return $booking;
    }

    /**
     * Бронювання, до якого водій має право звертатися: рівно ті точки,
     * що входять до його маршрутного листа. Чуже бронювання — 403,
     * незалежно від того, чий воно: іншого водія чи іншого постачальника.
     */
    private function ownPoint(Actor $actor, string $bookingId): Booking
    {
        $booking = $this->lifecycle->load($bookingId);
        $booking->assertDriverOwnsPoint($actor);

        return $booking;
    }
}
