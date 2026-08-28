<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Slot\StoreConfig;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Вікно відмітки «На місці» (розділ 8, блок DRV).
 *
 * Специфікація описує вікно ВЧАСНОГО прибуття як «від −60 хв до кінця слоту»,
 * а все, що пізніше за кінець слоту, — як прибуття із запізненням. Обидві
 * межі живуть тут, а не розсипані по контролерах: питання «чи можна зараз
 * відмітити прибуття» має рівно одну відповідь для всіх контурів.
 *
 * ТРИ МОМЕНТИ, а не два:
 *
 *   dayOfVisitStart ──── opensAt ──── slotStart ──── closesAt ────▶
 *   (київська північ)   (−60 хв)                    (кінець слоту)
 *        │                  │                            │
 *   раніше — відмова    зарано (early)   вчасно      із запізненням (late)
 *
 * ЧОМУ ЖОРСТКА МЕЖА — ДОБА, А НЕ −60 ХВ. Дефект, який закриває це правило
 * (ISSUE-13), — «На місці» можна натиснути ЗА ДОБУ до слоту: випадковий дотик
 * ставить у чергу магазину машину, якої немає, і водій не може це відкотити.
 * Саме доба й відсікається: раніше за київську північ дати слоту відмітка
 * не приймається взагалі.
 *
 * Робити жорсткою межею саме −60 хв не можна: маршрутний лист водій відкриває
 * вранці, а точки в ньому — на весь день, тож о 03:10 відмітити прибуття на
 * власну точку о 13:00 було б неможливо навіть тоді, коли машина вже під
 * рампою. Тому −60 хв лишається межею ВЧАСНОСТІ (`early`/`on_time`), а не
 * забороною; ширину жорсткого вікна має остаточно закріпити продукт.
 */
final readonly class ArrivalWindow
{
    /** Наскільки раніше за слот відкривається вікно вчасного прибуття. */
    public const int OPENS_BEFORE_SLOT_MINUTES = 60;

    /** Прибуття раніше, ніж відкрилося вікно вчасності. */
    public const string EARLY = 'early';

    /** Прибуття у вікні «−60 хв … кінець слоту». */
    public const string ON_TIME = 'on_time';

    /** Прибуття після кінця слоту — та сама «позначка запізнення». */
    public const string LATE = 'late';

    private function __construct(
        /** Київська північ дати слоту — раніше за неї відмітка неможлива. */
        public DateTimeImmutable $dayOfVisitStart,
        /** slotStart − 60 хв: відкриття вікна вчасного прибуття. */
        public DateTimeImmutable $opensAt,
        /** Кінець слоту: далі прибуття вважається запізненням. */
        public DateTimeImmutable $closesAt,
        /** Локальна дата візиту, Y-m-d у зоні магазину. */
        public string $localDate,
        /** Локальний час початку слоту, H:i у зоні магазину. */
        public string $localSlotTime,
    ) {
    }

    public static function forSlot(DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd): self
    {
        $timezone = new DateTimeZone(StoreConfig::TIMEZONE);
        $local = $slotStart->setTimezone($timezone);

        return new self(
            dayOfVisitStart: $local->setTime(0, 0),
            opensAt: $slotStart->modify(\sprintf('-%d minutes', self::OPENS_BEFORE_SLOT_MINUTES)),
            closesAt: $slotEnd,
            localDate: $local->format('Y-m-d'),
            localSlotTime: $local->format('H:i'),
        );
    }

    /**
     * Момент ще не настав у календарі магазину: візит призначено на іншу,
     * пізнішу добу. Порівнюються абсолютні моменти, тому зона `$now` значення
     * не має.
     */
    public function isBeforeDayOfVisit(DateTimeImmutable $now): bool
    {
        return $now < $this->dayOfVisitStart;
    }

    /** Раніше за −60 хв: машина на місці задовго до слоту. */
    public function isEarly(DateTimeImmutable $now): bool
    {
        return $now < $this->opensAt;
    }

    /** Пізніше за кінець слоту — прибуття із запізненням. */
    public function isLate(DateTimeImmutable $now): bool
    {
        return $now > $this->closesAt;
    }

    /** early | on_time | late — рівно те, що бачить магазин і аналітика. */
    public function punctuality(DateTimeImmutable $now): string
    {
        if ($this->isLate($now)) {
            return self::LATE;
        }

        return $this->isEarly($now) ? self::EARLY : self::ON_TIME;
    }

    /** Дата візиту у вигляді, придатному для повідомлення водієві. */
    public function localDayLabel(): string
    {
        return $this->dayOfVisitStart->format('d.m.Y');
    }
}
