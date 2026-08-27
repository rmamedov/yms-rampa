<?php

declare(strict_types=1);

namespace App\Domain\PartnerUser;

use App\Domain\Shared\PhoneNormalizer;
use App\Domain\Shared\ValidationException;

/**
 * Бізнес-профіль користувача партнерського контуру (розділ 10.4 `partner_users`).
 *
 * DATA-35: у цьому сервісі НЕМАЄ жодного `passwordHash` — креденшли
 * (login, passwordHash, role, active) живуть виключно в
 * `identity_partner.partner_accounts`. Зв'язок профіль↔акаунт — поле
 * `accountId` тут і `driverProfileId` там.
 */
final class PartnerUser
{
    public const MAX_NAME_LENGTH = 100;

    private ?string $phone;
    private ?string $firstName;
    private ?string $lastName;
    private ?string $defaultVehicleId;
    private bool $active = true;
    private ?\DateTimeImmutable $archivedAt = null;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly string $id,
        private string $accountId,
        private readonly PartnerUserType $type,
        private readonly string $supplierId,
        ?string $phone,
        ?string $firstName,
        ?string $lastName,
        ?string $defaultVehicleId,
        private readonly \DateTimeImmutable $createdAt,
        private readonly int $schemaVersion = 2,
    ) {
        $this->phone = $phone;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->defaultVehicleId = $defaultVehicleId;
        $this->updatedAt = $createdAt;
    }

    /**
     * Профіль водія (SUP-DRV-02). Телефон обов'язковий і водночас є логіном
     * у identity-partner-service; нормалізується до +380XXXXXXXXX.
     */
    public static function driver(
        string $id,
        string $accountId,
        string $supplierId,
        string $phone,
        string $firstName,
        string $lastName,
        ?string $defaultVehicleId,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            accountId: $accountId,
            type: PartnerUserType::Driver,
            supplierId: self::assertSupplierId($supplierId),
            phone: PhoneNormalizer::normalize($phone),
            firstName: self::assertPersonName($firstName, 'ім\'я'),
            lastName: self::assertPersonName($lastName, 'прізвище'),
            defaultVehicleId: self::blankToNull($defaultVehicleId),
            createdAt: $createdAt,
        );
    }

    /**
     * Профіль користувача кабінету постачальника (SUP-04).
     */
    public static function supplierUser(
        string $id,
        string $accountId,
        string $supplierId,
        ?string $firstName,
        ?string $lastName,
        ?string $phone,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            accountId: $accountId,
            type: PartnerUserType::Supplier,
            supplierId: self::assertSupplierId($supplierId),
            phone: PhoneNormalizer::normalizeOptional($phone),
            firstName: null === $firstName ? null : self::assertPersonName($firstName, 'ім\'я'),
            lastName: null === $lastName ? null : self::assertPersonName($lastName, 'прізвище'),
            defaultVehicleId: null,
            createdAt: $createdAt,
        );
    }

    /**
     * Відновлення агрегату зі сховища (без повторної валідації бізнес-правил,
     * бо документ уже пройшов їх при записі; DATA-02 — читаємо всі версії схеми).
     */
    public static function reconstitute(
        string $id,
        string $accountId,
        PartnerUserType $type,
        string $supplierId,
        ?string $phone,
        ?string $firstName,
        ?string $lastName,
        ?string $defaultVehicleId,
        bool $active,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $archivedAt,
        int $schemaVersion = 2,
    ): self {
        $user = new self(
            id: $id,
            accountId: $accountId,
            type: $type,
            supplierId: $supplierId,
            phone: $phone,
            firstName: $firstName,
            lastName: $lastName,
            defaultVehicleId: $defaultVehicleId,
            createdAt: $createdAt,
            schemaVersion: $schemaVersion,
        );
        $user->active = $active;
        $user->updatedAt = $updatedAt;
        $user->archivedAt = $archivedAt;

        return $user;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function type(): PartnerUserType
    {
        return $this->type;
    }

    public function isDriver(): bool
    {
        return PartnerUserType::Driver === $this->type;
    }

    public function supplierId(): string
    {
        return $this->supplierId;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): ?string
    {
        return $this->lastName;
    }

    public function fullName(): string
    {
        return trim(($this->lastName ?? '').' '.($this->firstName ?? ''));
    }

    public function defaultVehicleId(): ?string
    {
        return $this->defaultVehicleId;
    }

    public function isActive(): bool
    {
        return $this->active && null === $this->archivedAt;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function linkAccount(string $accountId, \DateTimeImmutable $now): void
    {
        $this->accountId = $accountId;
        $this->updatedAt = $now;
    }

    public function changePhone(string $phone, \DateTimeImmutable $now): void
    {
        $this->phone = PhoneNormalizer::normalize($phone);
        $this->updatedAt = $now;
    }

    public function rename(?string $firstName, ?string $lastName, \DateTimeImmutable $now): void
    {
        if (null !== $firstName) {
            $this->firstName = self::assertPersonName($firstName, 'ім\'я');
        }

        if (null !== $lastName) {
            $this->lastName = self::assertPersonName($lastName, 'прізвище');
        }

        $this->updatedAt = $now;
    }

    public function assignDefaultVehicle(?string $vehicleId, \DateTimeImmutable $now): void
    {
        $this->defaultVehicleId = self::blankToNull($vehicleId);
        $this->updatedAt = $now;
    }

    /**
     * SUP-DRV-05: деактивація водія. Вхід у driver-web блокується (окремо
     * вимикається акаунт в identity-partner-service), історія зберігається,
     * повторна активація можлива.
     *
     * @return bool true, якщо стан справді змінився
     */
    public function deactivate(\DateTimeImmutable $now): bool
    {
        if (!$this->active) {
            return false;
        }

        $this->active = false;
        $this->updatedAt = $now;

        return true;
    }

    public function activate(\DateTimeImmutable $now): bool
    {
        if ($this->active) {
            return false;
        }

        $this->active = true;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * DATA-03 + DATA-17: після архівації телефон звільняється і може бути
     * використаний іншим водієм (partial unique index має фільтр archivedAt:null).
     */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt = $now;
        $this->active = false;
        $this->updatedAt = $now;
    }

    public function belongsTo(string $supplierId): bool
    {
        return $this->supplierId === $supplierId;
    }

    private static function assertSupplierId(string $supplierId): string
    {
        $trimmed = trim($supplierId);

        if ('' === $trimmed) {
            throw new ValidationException('Не вказано постачальника.', 'SUPPLIER_ID_REQUIRED');
        }

        return $trimmed;
    }

    private static function assertPersonName(string $value, string $label): string
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new ValidationException(\sprintf('Вкажіть %s водія.', $label), 'PARTNER_USER_NAME_REQUIRED');
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new ValidationException(
                \sprintf('Поле «%s» не може бути довшим за %d символів.', $label, self::MAX_NAME_LENGTH),
                'PARTNER_USER_NAME_TOO_LONG',
            );
        }

        return $trimmed;
    }

    private static function blankToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
