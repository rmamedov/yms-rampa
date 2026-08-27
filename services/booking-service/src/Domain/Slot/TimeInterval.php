<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use InvalidArgumentException;

/**
 * Інтервал доби в локальному часі магазину, напр. 08:00–14:00.
 *
 * Нічні вікна прийому, що переходять через північ, задаються ДВОМА інтервалами
 * у сусідніх днях тижня (22:00–24:00 і 00:00–06:00), а не одним оберненим.
 */
final readonly class TimeInterval
{
    public int $fromMinutes;
    public int $toMinutes;

    public function __construct(string $from, string $to)
    {
        $this->fromMinutes = self::parse($from);
        $this->toMinutes = self::parse($to);

        if ($this->toMinutes <= $this->fromMinutes) {
            throw new InvalidArgumentException(
                \sprintf('Кінець інтервалу (%s) має бути пізніше за початок (%s)', $to, $from)
            );
        }
    }

    private static function parse(string $value): int
    {
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            throw new InvalidArgumentException(\sprintf('Час має бути у форматі HH:MM, отримано "%s"', $value));
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 24 || $minutes > 59 || ($hours === 24 && $minutes > 0)) {
            throw new InvalidArgumentException(\sprintf('Некоректний час доби "%s"', $value));
        }

        return $hours * 60 + $minutes;
    }

    public function durationMinutes(): int
    {
        return $this->toMinutes - $this->fromMinutes;
    }

    public function formatFrom(): string
    {
        return self::format($this->fromMinutes);
    }

    public function formatTo(): string
    {
        return self::format($this->toMinutes);
    }

    private static function format(int $minutes): string
    {
        return \sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
