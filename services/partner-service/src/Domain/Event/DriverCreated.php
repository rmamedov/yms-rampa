<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Канонічна подія `DriverCreated` (SUP-DRV-03, DATA-35).
 *
 * Споживач — notification-service: надсилає водієві SMS із логіном (телефон)
 * і згенерованим паролем за шаблоном `driver_password_created`.
 *
 * Пароль передається в події транзитом і НІКОЛИ не зберігається: у partner-service
 * його немає взагалі (DATA-35), у notification-service тіло SMS не логується
 * (DATA-21). Прапорець `passwordRegenerated` розрізняє первинне створення
 * (SUP-DRV-03) і перегенерацію пароля (SUP-DRV-04).
 */
final readonly class DriverCreated implements DomainEvent
{
    public function __construct(
        public string $driverProfileId,
        public string $accountId,
        public string $supplierId,
        public string $supplierName,
        public string $phone,
        public string $firstName,
        public string $lastName,
        public string $password,
        public \DateTimeImmutable $occurredAt,
        public bool $passwordRegenerated = false,
    ) {
    }

    public function eventType(): string
    {
        return 'DriverCreated';
    }

    public function aggregateId(): string
    {
        return $this->driverProfileId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function payload(): array
    {
        return [
            'driverProfileId' => $this->driverProfileId,
            'accountId' => $this->accountId,
            'supplierId' => $this->supplierId,
            'supplierName' => $this->supplierName,
            'phone' => $this->phone,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'login' => $this->phone,
            'password' => $this->password,
            'passwordRegenerated' => $this->passwordRegenerated,
            'occurredAt' => $this->occurredAt->format(\DATE_ATOM),
        ];
    }
}
