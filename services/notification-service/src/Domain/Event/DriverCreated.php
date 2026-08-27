<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Водія створено (NOT-15).
 *
 * Подію публікує partner-service після створення бізнес-профілю водія.
 * `oneTimePassword` приходить у payload події; notification-service його
 * НЕ генерує, НЕ логує і НЕ зберігає після відправки SMS.
 */
final readonly class DriverCreated implements DomainEvent
{
    public function __construct(
        public string $driverId,
        public string $fullName,
        /** Логін водія — телефон у форматі +380XXXXXXXXX. */
        public string $phone,
        public string $oneTimePassword,
        public string $loginUrl,
        public ?string $supplierId = null,
        public ?\DateTimeImmutable $occurredAtUtc = null,
    ) {
    }

    public function eventName(): string
    {
        return 'DriverCreated';
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAtUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
