<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Подія StoreConfigChanged — створено нову версію конфігурації магазину або
 * змінено правила резервів/блокувань, що впливають на сітку слотів (STC-60).
 */
final readonly class StoreConfigChanged implements DomainEvent
{
    /**
     * @param 'configuration'|'reserved_slot_rule'|'slot_block'|'yms_fields' $scope
     */
    public function __construct(
        public string $storeId,
        public string $scope,
        public ?int $version,
        public ?\DateTimeImmutable $effectiveFrom,
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    public function name(): string
    {
        return 'StoreConfigChanged';
    }

    public function payload(): array
    {
        return [
            'storeId' => $this->storeId,
            'scope' => $this->scope,
            'version' => $this->version,
            'effectiveFrom' => $this->effectiveFrom?->format(\DATE_ATOM),
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
