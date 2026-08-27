<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Канонічний ідентифікатор слота (SLOT-02): трійка storeId + rampId + slotStart.
 * Окремого slotId не існує — бронювання і блокування посилаються на цю трійку.
 */
final readonly class SlotKey
{
    public DateTimeImmutable $slotStart;

    public function __construct(
        public string $storeId,
        public string $rampId,
        DateTimeImmutable $slotStart,
    ) {
        if ('' === $storeId || '' === $rampId) {
            throw new InvalidArgumentException('storeId та rampId не можуть бути порожніми');
        }

        $this->slotStart = $slotStart->setTimezone(new DateTimeZone('UTC'));
    }

    /** Стабільний рядковий вигляд ключа — для Redis-холдів і порівнянь. */
    public function toString(): string
    {
        return \sprintf('%s|%s|%s', $this->storeId, $this->rampId, $this->slotStart->format('Y-m-d\TH:i:s\Z'));
    }

    public function equals(self $other): bool
    {
        return $this->storeId === $other->storeId
            && $this->rampId === $other->rampId
            && $this->slotStart->getTimestamp() === $other->slotStart->getTimestamp();
    }
}
