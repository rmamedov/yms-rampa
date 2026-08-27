<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Подія SlotReleased — блокування знято достроково, слоти повертаються в available (STC-52).
 */
final readonly class SlotReleased implements DomainEvent
{
    /**
     * @param list<string> $rampIds порожній список = усі рампи магазину
     */
    public function __construct(
        public string $storeId,
        public array $rampIds,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $reason,
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    public function name(): string
    {
        return 'SlotReleased';
    }

    public function payload(): array
    {
        return [
            'storeId' => $this->storeId,
            'rampIds' => $this->rampIds,
            'from' => $this->from->format(\DATE_ATOM),
            'to' => $this->to->format(\DATE_ATOM),
            'reason' => $this->reason,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
