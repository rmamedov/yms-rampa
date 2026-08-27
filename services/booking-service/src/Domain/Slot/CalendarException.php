<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use InvalidArgumentException;

/**
 * Виняток у календарі магазину на конкретну дату: свято, інвентаризація
 * або нестандартний графік прийому. Перекриває звичайне вікно дня тижня.
 */
final readonly class CalendarException
{
    /** @var list<TimeInterval> */
    public array $intervals;

    /**
     * @param string             $date      дата в локальному часі магазину, Y-m-d
     * @param bool               $closed    true — магазин не приймає поставок цього дня
     * @param list<TimeInterval> $intervals нестандартні інтервали; ігноруються, якщо closed
     */
    public function __construct(
        public string $date,
        public bool $closed,
        array $intervals = [],
        public ?string $reason = null,
    ) {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException(\sprintf('Дата має бути у форматі Y-m-d, отримано "%s"', $date));
        }

        if (!$closed && [] === $intervals) {
            throw new InvalidArgumentException('Виняток без closed має задавати щонайменше один інтервал');
        }

        $this->intervals = array_values($intervals);
    }
}
