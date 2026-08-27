<?php

declare(strict_types=1);

namespace App\Application\Outbox;

/**
 * Присуд споживача щодо однієї доставленої події.
 *
 * Значення дослівно повторюють контракт analytics-service
 * (InternalEventIngestController, поле `outcome`).
 */
enum EventOutcome: string
{
    /** Подію застосовано до read-моделі. */
    case Applied = 'applied';
    /** Подія вже була застосована раніше — повторна доставка (at-least-once). */
    case Duplicate = 'duplicate';
    /** Подія не стосується read-моделі споживача. */
    case Ignored = 'ignored';
    /** Подія бронювання, для якого споживач ще не бачив BookingCreated. */
    case Orphan = 'orphan';
    /** Подію не вдалося розібрати: бракує обовʼязкового поля, невідома назва. */
    case Rejected = 'rejected';

    /**
     * Чи можна прибирати запис із черги.
     *
     * Тільки ці три присуди означають, що подія свою роботу зробила:
     * застосована, вже була застосована або споживачу не потрібна. Сирота і
     * відхилення — НЕ доставка: такий запис іде в карантин, а не в «опубліковані».
     * Саме через відсутність цієї межі перший прогін релея на стенді позначив
     * доставленими 1301 подію, з яких застосовано було 765, а решта зникла
     * без сліду.
     */
    public function isDelivered(): bool
    {
        return match ($this) {
            self::Applied, self::Duplicate, self::Ignored => true,
            self::Orphan, self::Rejected => false,
        };
    }

    /** Людський опис для журналу команди. */
    public function label(): string
    {
        return match ($this) {
            self::Applied => 'застосовано',
            self::Duplicate => 'дублікат',
            self::Ignored => 'не стосується read-моделі',
            self::Orphan => 'сирота: немає BookingCreated',
            self::Rejected => 'відхилено',
        };
    }
}
