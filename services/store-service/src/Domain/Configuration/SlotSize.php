<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Розмір слоту розвантаження (STC-20, DATA-10).
 *
 * Раніше це був перелік із чотирьох значень (15/20/30/60). Тепер розмір
 * задається з кроком у 5 хвилин: різні філії розвантажують по-різному, і
 * чотирьох варіантів для цього замало.
 *
 * Крок саме 5 хвилин — той самий, що й в інтервалів вікна прийому
 * (TimeInterval): інакше вікно 06:00–16:00 не ділилося б на слоти без
 * залишку, і частина сітки просто не будувалася б.
 */
final readonly class SlotSize
{
    public const int MIN_MINUTES = 5;
    public const int MAX_MINUTES = 120;
    public const int STEP_MINUTES = 5;

    private function __construct(public int $value)
    {
    }

    public static function fromMinutes(int $minutes): self
    {
        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            throw ValidationException::config(
                \sprintf(
                    'Розмір слоту %d хв поза межами; допустимо від %d до %d хвилин',
                    $minutes,
                    self::MIN_MINUTES,
                    self::MAX_MINUTES,
                ),
                ['slotSizeMinutes' => \sprintf(
                    'Від %d до %d хвилин',
                    self::MIN_MINUTES,
                    self::MAX_MINUTES,
                )],
            );
        }

        if (0 !== $minutes % self::STEP_MINUTES) {
            throw ValidationException::config(
                \sprintf('Розмір слоту %d хв не кратний %d хвилинам', $minutes, self::STEP_MINUTES),
                ['slotSizeMinutes' => \sprintf('Крок — %d хвилин', self::STEP_MINUTES)],
            );
        }

        return new self($minutes);
    }

    /**
     * Усі допустимі значення — для довідників і для перевірок.
     *
     * @return list<int>
     */
    public static function values(): array
    {
        $values = [];

        for ($m = self::MIN_MINUTES; $m <= self::MAX_MINUTES; $m += self::STEP_MINUTES) {
            $values[] = $m;
        }

        return $values;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
