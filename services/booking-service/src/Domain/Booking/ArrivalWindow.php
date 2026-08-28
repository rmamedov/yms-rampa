<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Slot\StoreConfig;
use App\Domain\Store\StorePolicy;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Вікно відмітки «На місці» (розділ 8, блок DRV).
 *
 * Специфікація: відмітка доступна ВІД −60 ХВ ДО КІНЦЯ СЛОТУ, а після кінця
 * слоту приймається з позначкою запізнення. Обидві межі живуть тут, а не
 * розсипані по контролерах: питання «чи можна зараз відмітити прибуття» має
 * рівно одну відповідь для всіх контурів.
 *
 *   ──── opensAt ──── slotStart ──── closesAt ────▶
 *        (−60 хв)                   (кінець слоту)
 *     ↑                ↑                    ↑
 *  відмова        вчасно               із запізненням
 *  ARRIVAL_TOO_EARLY                   (late = true)
 *
 * ШИРИНА ВІКНА — `StorePolicy::ARRIVAL_WINDOW_MINUTES`, і лише там: щоб
 * змінити правило, достатньо однієї константи. Застосунок водія дзеркалить
 * те саме значення (`ARRIVAL_WINDOW_MINUTES` у route-sheet.model.ts), інакше
 * кнопка з'являлася б раніше, ніж бекенд готовий прийняти відмітку.
 *
 * ЧОМУ МЕЖА ЖОРСТКА. Без неї «На місці» можна натиснути хоч за добу (ISSUE-13):
 * випадковий дотик о 06:00 ставить у чергу магазину машину, яку чекають о 22:00,
 * і водій не може це відкотити. Вікно і lead time бронювання узгоджені: слот
 * можна забронювати не раніше ніж за годину від «зараз», тому для щойно
 * створеного бронювання вікно прибуття відкривається практично одразу.
 */
final readonly class ArrivalWindow
{
    private function __construct(
        /** slotStart − StorePolicy::ARRIVAL_WINDOW_MINUTES: відкриття вікна. */
        public DateTimeImmutable $opensAt,
        /** Кінець слоту: далі прибуття вважається запізненням. */
        public DateTimeImmutable $closesAt,
        /** Локальна дата візиту, Y-m-d у зоні магазину. */
        public string $localDate,
        /** Локальний час відкриття вікна, H:i. */
        public string $localOpensAt,
        /** Локальний час початку слоту, H:i. */
        public string $localSlotTime,
    ) {
    }

    public static function forSlot(DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd): self
    {
        $timezone = new DateTimeZone(StoreConfig::TIMEZONE);
        $opensAt = $slotStart->modify(\sprintf('-%d minutes', StorePolicy::ARRIVAL_WINDOW_MINUTES));

        return new self(
            opensAt: $opensAt,
            closesAt: $slotEnd,
            localDate: $slotStart->setTimezone($timezone)->format('Y-m-d'),
            localOpensAt: $opensAt->setTimezone($timezone)->format('H:i'),
            localSlotTime: $slotStart->setTimezone($timezone)->format('H:i'),
        );
    }

    /**
     * Вікно ще не відкрилося. Порівнюються абсолютні моменти, тому зона
     * `$now` значення не має.
     */
    public function isBeforeOpening(DateTimeImmutable $now): bool
    {
        return $now < $this->opensAt;
    }

    /** Пізніше за кінець слоту — прибуття із запізненням. */
    public function isLate(DateTimeImmutable $now): bool
    {
        return $now > $this->closesAt;
    }

    /** Дата відкриття вікна у вигляді, придатному для повідомлення водієві. */
    public function opensOnLocalDate(): string
    {
        return $this->opensAt->setTimezone(new DateTimeZone(StoreConfig::TIMEZONE))->format('d.m.Y');
    }

    /** Локальна дата моменту — щоб не називати дату там, де подія сьогоднішня. */
    public function isSameLocalDay(DateTimeImmutable $moment): bool
    {
        $timezone = new DateTimeZone(StoreConfig::TIMEZONE);

        return $moment->setTimezone($timezone)->format('Y-m-d')
            === $this->opensAt->setTimezone($timezone)->format('Y-m-d');
    }
}
