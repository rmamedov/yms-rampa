<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Один обчислений слот сітки. Не зберігається в БД — існує лише у відповіді API.
 */
final readonly class Slot
{
    public function __construct(
        public SlotKey $key,
        public DateTimeImmutable $slotEnd,
        public SlotState $state,
        /** Слот зарезервовано саме за тим постачальником, який дивиться сітку (GRID-04). */
        public bool $reservedForViewer = false,
        /** Заповнюється лише для співробітників мережі; постачальникам чужі резерви не розкриваються. */
        public ?string $reservedForSupplierId = null,
        public ?string $blockReason = null,
    ) {
    }

    public function isSelectable(): bool
    {
        return $this->state->isSelectable();
    }

    public function localStartTime(): string
    {
        return $this->key->slotStart
            ->setTimezone(new DateTimeZone(StoreConfig::TIMEZONE))
            ->format('H:i');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'rampId' => $this->key->rampId,
            'slotStart' => $this->key->slotStart->format('Y-m-d\TH:i:s\Z'),
            'slotEnd' => $this->slotEnd->format('Y-m-d\TH:i:s\Z'),
            'localStart' => $this->localStartTime(),
            'state' => $this->state->value,
            'selectable' => $this->isSelectable(),
        ];

        if ($this->reservedForViewer) {
            $payload['reservedForYou'] = true;
        }

        if (null !== $this->reservedForSupplierId) {
            $payload['reservedForSupplierId'] = $this->reservedForSupplierId;
        }

        if (null !== $this->blockReason) {
            $payload['blockReason'] = $this->blockReason;
        }

        return $payload;
    }
}
