<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Інтервал прийому HH:MM–HH:MM у локальному часі магазину (STC-10, STC-11).
 *
 * Валідації: формат HH:MM, крок 5 хвилин, початок строго менший за кінець.
 */
final readonly class TimeInterval
{
    public const int STEP_MINUTES = 5;

    public int $startMinutes;
    public int $endMinutes;

    public function __construct(
        public string $from,
        public string $to,
    ) {
        $this->startMinutes = self::parse($from, 'from');
        $this->endMinutes = self::parse($to, 'to');

        if ($this->startMinutes >= $this->endMinutes) {
            throw ValidationException::config(
                \sprintf('Початок інтервалу %s має бути раніше за кінець %s', $from, $to),
                ['intervals' => 'Початок інтервалу має бути раніше за кінець'],
            );
        }
    }

    /** Розбір «HH:MM» у хвилини від початку доби з перевіркою кроку 5 хв. */
    public static function parse(string $value, string $field = 'time'): int
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            throw ValidationException::config(
                \sprintf('Час «%s» має бути у форматі HH:MM', $value),
                [$field => 'Очікується формат HH:MM'],
            );
        }

        $minutes = ((int) $m[1]) * 60 + (int) $m[2];

        if (0 !== $minutes % self::STEP_MINUTES) {
            throw ValidationException::config(
                \sprintf('Час «%s» має бути кратним %d хвилинам', $value, self::STEP_MINUTES),
                [$field => 'Крок часу — 5 хвилин'],
            );
        }

        return $minutes;
    }

    public function durationMinutes(): int
    {
        return $this->endMinutes - $this->startMinutes;
    }

    public function overlaps(self $other): bool
    {
        return $this->startMinutes < $other->endMinutes && $other->startMinutes < $this->endMinutes;
    }

    public function containsStart(int $minutes): bool
    {
        return $minutes >= $this->startMinutes && $minutes < $this->endMinutes;
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }
}
