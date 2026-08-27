<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Канонічна подія `SupplierSuspended` (SUP-02).
 *
 * Споживачі: identity-partner-service (блокує логін усіх акаунтів
 * постачальника), notification-service, analytics-service.
 * Чинні бронювання при цьому НЕ скасовуються.
 */
final readonly class SupplierSuspended implements DomainEvent
{
    public function __construct(
        public string $supplierId,
        public string $supplierName,
        public ?string $reason,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public function eventType(): string
    {
        return 'SupplierSuspended';
    }

    public function aggregateId(): string
    {
        return $this->supplierId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function payload(): array
    {
        return [
            'supplierId' => $this->supplierId,
            'supplierName' => $this->supplierName,
            'reason' => $this->reason,
            'occurredAt' => $this->occurredAt->format(\DATE_ATOM),
        ];
    }
}
