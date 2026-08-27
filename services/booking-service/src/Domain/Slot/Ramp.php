<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use InvalidArgumentException;

/**
 * Рампа магазину — незалежний паралельний потік слотів.
 * Бронювання завжди привʼязане до конкретної рампи.
 */
final readonly class Ramp
{
    public function __construct(
        public string $rampId,
        public string $name,
        public bool $active = true,
    ) {
        if ('' === $rampId) {
            throw new InvalidArgumentException('rampId не може бути порожнім');
        }
    }
}
