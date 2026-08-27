<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Вікна прийому одного дня тижня: 0..N інтервалів, що не перетинаються (STC-10, STC-11).
 * dayOfWeek 1 = понеділок ... 7 = неділя (ISO-8601, 10.2.2).
 */
final readonly class ReceivingWindow
{
    /** @var list<TimeInterval> */
    public array $intervals;

    /**
     * @param list<TimeInterval> $intervals
     */
    public function __construct(
        public int $dayOfWeek,
        array $intervals,
    ) {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw ValidationException::config(
                'День тижня має бути в межах 1–7',
                ['dayOfWeek' => 'Допустимі значення 1–7'],
            );
        }

        usort($intervals, static fn (TimeInterval $a, TimeInterval $b): int => $a->startMinutes <=> $b->startMinutes);

        $count = \count($intervals);

        for ($i = 1; $i < $count; ++$i) {
            if ($intervals[$i - 1]->overlaps($intervals[$i])) {
                throw ValidationException::config(
                    \sprintf(
                        'Інтервали прийому одного дня не можуть перетинатися: %s–%s і %s–%s',
                        $intervals[$i - 1]->from,
                        $intervals[$i - 1]->to,
                        $intervals[$i]->from,
                        $intervals[$i]->to,
                    ),
                    ['intervals' => 'Інтервали одного дня перетинаються'],
                );
            }
        }

        $this->intervals = array_values($intervals);
    }

    public function totalMinutes(): int
    {
        return array_sum(array_map(static fn (TimeInterval $i): int => $i->durationMinutes(), $this->intervals));
    }

    public function isEmpty(): bool
    {
        return [] === $this->intervals;
    }
}
