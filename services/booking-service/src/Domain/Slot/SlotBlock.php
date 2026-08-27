<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Разове блокування слотів магазином або адміністратором
 * (рампа несправна, ремонт, інвентаризація).
 */
final readonly class SlotBlock
{
    public DateTimeImmutable $from;
    public DateTimeImmutable $to;

    /**
     * @param string|null $rampId null — блокування діє на всі рампи магазину
     */
    public function __construct(
        public string $storeId,
        public ?string $rampId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        public ?string $reason = null,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->from = $from->setTimezone($utc);
        $this->to = $to->setTimezone($utc);

        if ($this->to <= $this->from) {
            throw new InvalidArgumentException('Кінець блокування має бути пізніше за початок');
        }
    }

    /** Чи перетинається блокування зі слотом [slotStart, slotEnd). */
    public function covers(SlotKey $key, DateTimeImmutable $slotEnd): bool
    {
        if ($key->storeId !== $this->storeId) {
            return false;
        }

        if (null !== $this->rampId && $key->rampId !== $this->rampId) {
            return false;
        }

        return $key->slotStart < $this->to && $slotEnd > $this->from;
    }
}
