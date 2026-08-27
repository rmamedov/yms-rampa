<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use InvalidArgumentException;

/**
 * Рампа магазину — незалежний паралельний потік слотів.
 * Бронювання завжди привʼязане до конкретної рампи.
 *
 * `rampId` — службовий ідентифікатор (напр. «r1»), його бачить лише код.
 * Людині показують `number` і `name` — саме вони написані на воротах, тож
 * контур водія віддає їх у точці маршрутного листа (див. RouteSheetService).
 */
final readonly class Ramp
{
    public function __construct(
        public string $rampId,
        public string $name,
        public bool $active = true,
        /** Номер рампи в межах магазину; null, якщо сусід його не надіслав. */
        public ?int $number = null,
    ) {
        if ('' === $rampId) {
            throw new InvalidArgumentException('rampId не може бути порожнім');
        }
    }
}
