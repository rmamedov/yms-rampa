<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Перепризначення рампи / авто / водія (NOT-18).
 *
 * Що саме змінилося, описують поля `newRampNumber`, `newVehicleNumber`
 * і `driverChanged`; з них будується підстановка {changes} шаблону NOT-T9.
 */
final readonly class BookingReassigned implements DomainEvent
{
    public function __construct(
        public string $bookingId,
        public \DateTimeImmutable $slotStartUtc,
        public string $storeExternalId,
        public ReassignmentInitiator $initiator,
        public ?string $newRampNumber = null,
        public ?string $newVehicleNumber = null,
        public bool $driverChanged = false,
        public ?string $supplierId = null,
        public ?string $supplierEmail = null,
        public ?string $driverId = null,
        public ?string $driverPhone = null,
        public string $portalUrl = '',
        public ?\DateTimeImmutable $occurredAtUtc = null,
    ) {
    }

    /**
     * Опис змін українською для підстановки {changes} шаблону NOT-T9:
     * «рампа 5 / авто AA1234BB / водій».
     */
    public function changesDescription(): string
    {
        $parts = [];

        if (null !== $this->newRampNumber && '' !== $this->newRampNumber) {
            $parts[] = 'рампа '.$this->newRampNumber;
        }
        if (null !== $this->newVehicleNumber && '' !== $this->newVehicleNumber) {
            $parts[] = 'авто '.$this->newVehicleNumber;
        }
        if ($this->driverChanged) {
            $parts[] = 'водій';
        }

        return implode(' / ', $parts);
    }

    public function hasChanges(): bool
    {
        return '' !== $this->changesDescription();
    }

    public function eventName(): string
    {
        return 'BookingReassigned';
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAtUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
