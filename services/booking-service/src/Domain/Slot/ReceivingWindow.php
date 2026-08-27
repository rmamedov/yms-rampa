<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use InvalidArgumentException;

/**
 * Вікно прийому поставок на конкретний день тижня.
 * Слоти генеруються тільки всередині вікон прийому.
 */
final readonly class ReceivingWindow
{
    /** @var list<TimeInterval> */
    public array $intervals;

    /**
     * @param int                $dayOfWeek 1 = понеділок … 7 = неділя (ISO-8601)
     * @param list<TimeInterval> $intervals один або кілька інтервалів на день
     */
    public function __construct(
        public int $dayOfWeek,
        array $intervals,
    ) {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new InvalidArgumentException(\sprintf('dayOfWeek має бути 1..7, отримано %d', $dayOfWeek));
        }

        if ([] === $intervals) {
            throw new InvalidArgumentException('Вікно прийому має містити щонайменше один інтервал');
        }

        $sorted = $intervals;
        usort($sorted, static fn (TimeInterval $a, TimeInterval $b) => $a->fromMinutes <=> $b->fromMinutes);

        $previous = null;
        foreach ($sorted as $interval) {
            if (null !== $previous && $interval->fromMinutes < $previous->toMinutes) {
                throw new InvalidArgumentException(\sprintf(
                    'Інтервали вікна прийому не можуть перетинатися: %s–%s та %s–%s',
                    $previous->formatFrom(),
                    $previous->formatTo(),
                    $interval->formatFrom(),
                    $interval->formatTo(),
                ));
            }
            $previous = $interval;
        }

        $this->intervals = array_values($sorted);
    }
}
