<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

use App\Domain\Shared\ValidationException;

/**
 * Постачальник (SUP-01…SUP-06, розділ 10.4 колекція `suppliers`).
 */
final class Supplier
{
    public const MAX_NAME_LENGTH = 200;

    private string $name;
    private ?string $edrpou;
    private SupplierStatus $status = SupplierStatus::Active;
    private StoreAccess $storeAccess;
    /** @var list<SupplierContact> */
    private array $contacts;
    private ?\DateTimeImmutable $suspendedAt = null;
    private ?string $suspendReason = null;
    private ?\DateTimeImmutable $archivedAt = null;
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<SupplierContact> $contacts
     */
    public function __construct(
        private readonly string $id,
        string $name,
        ?string $edrpou,
        ?StoreAccess $storeAccess,
        array $contacts,
        private readonly \DateTimeImmutable $createdAt,
        private readonly int $schemaVersion = 1,
    ) {
        $this->name = self::assertName($name);
        $this->edrpou = self::assertEdrpou($edrpou);
        $this->storeAccess = $storeAccess ?? StoreAccess::allStores();
        $this->contacts = array_values($contacts);
        $this->updatedAt = $createdAt;
    }

    /**
     * Відновлення агрегату зі сховища без повторної валідації правил створення
     * (DATA-02: читаємо документи всіх підтримуваних версій схеми).
     *
     * @param list<SupplierContact> $contacts
     */
    public static function reconstitute(
        string $id,
        string $name,
        ?string $edrpou,
        SupplierStatus $status,
        StoreAccess $storeAccess,
        array $contacts,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $suspendedAt,
        ?string $suspendReason,
        ?\DateTimeImmutable $archivedAt,
        int $schemaVersion = 1,
    ): self {
        $supplier = new self($id, $name, $edrpou, $storeAccess, $contacts, $createdAt, $schemaVersion);
        $supplier->status = $status;
        $supplier->updatedAt = $updatedAt;
        $supplier->suspendedAt = $suspendedAt;
        $supplier->suspendReason = $suspendReason;
        $supplier->archivedAt = $archivedAt;

        return $supplier;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function edrpou(): ?string
    {
        return $this->edrpou;
    }

    public function status(): SupplierStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return SupplierStatus::Active === $this->status && null === $this->archivedAt;
    }

    public function storeAccess(): StoreAccess
    {
        return $this->storeAccess;
    }

    /** @return list<SupplierContact> */
    public function contacts(): array
    {
        return $this->contacts;
    }

    public function suspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function suspendReason(): ?string
    {
        return $this->suspendReason;
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

    public function rename(string $name, \DateTimeImmutable $now): void
    {
        $this->name = self::assertName($name);
        $this->updatedAt = $now;
    }

    public function changeEdrpou(?string $edrpou, \DateTimeImmutable $now): void
    {
        $this->edrpou = self::assertEdrpou($edrpou);
        $this->updatedAt = $now;
    }

    public function changeStoreAccess(StoreAccess $storeAccess, \DateTimeImmutable $now): void
    {
        $this->storeAccess = $storeAccess;
        $this->updatedAt = $now;
    }

    /**
     * @param list<SupplierContact> $contacts
     */
    public function replaceContacts(array $contacts, \DateTimeImmutable $now): void
    {
        $this->contacts = array_values($contacts);
        $this->updatedAt = $now;
    }

    /**
     * SUP-02: переведення в `suspended`. Повертає true, якщо статус справді
     * змінився — лише тоді має сенс публікувати подію SupplierSuspended
     * і блокувати акаунти в identity-partner-service.
     */
    public function suspend(\DateTimeImmutable $now, ?string $reason = null): bool
    {
        if (SupplierStatus::Suspended === $this->status) {
            return false;
        }

        $this->status = SupplierStatus::Suspended;
        $this->suspendedAt = $now;
        $this->suspendReason = self::assertReason($reason);
        $this->updatedAt = $now;

        return true;
    }

    public function activate(\DateTimeImmutable $now): bool
    {
        if (SupplierStatus::Active === $this->status) {
            return false;
        }

        $this->status = SupplierStatus::Active;
        $this->suspendedAt = null;
        $this->suspendReason = null;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * DATA-03: soft delete. Дозволено лише тоді, коли в постачальника немає
     * жодного бронювання (SUP-06) — перевірку робить SupplierService.
     */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt = $now;
        $this->status = SupplierStatus::Suspended;
        $this->updatedAt = $now;
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);

        if ('' === $trimmed) {
            throw new ValidationException('Вкажіть назву постачальника.', 'SUPPLIER_NAME_REQUIRED');
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new ValidationException(
                \sprintf('Назва постачальника не може бути довшою за %d символів.', self::MAX_NAME_LENGTH),
                'SUPPLIER_NAME_TOO_LONG',
            );
        }

        return $trimmed;
    }

    /**
     * SUP-01: ЄДРПОУ — 8 або 10 цифр (10 — для фізичних осіб-підприємців,
     * де використовується РНОКПП). Порожнє значення допустиме (розділ 10.4).
     */
    public static function normalizeEdrpou(?string $edrpou): ?string
    {
        return self::assertEdrpou($edrpou);
    }

    public static function normalizeName(string $name): string
    {
        return self::assertName($name);
    }

    private static function assertEdrpou(?string $edrpou): ?string
    {
        if (null === $edrpou) {
            return null;
        }

        $digitsOnly = preg_replace('/\s+/', '', trim($edrpou)) ?? '';

        if ('' === $digitsOnly) {
            return null;
        }

        if (1 !== preg_match('/^\d{8}$|^\d{10}$/', $digitsOnly)) {
            throw new ValidationException(
                \sprintf('Код ЄДРПОУ «%s» має складатися з 8 або 10 цифр.', trim($edrpou)),
                'SUPPLIER_EDRPOU_INVALID',
            );
        }

        return $digitsOnly;
    }

    private static function assertReason(?string $reason): ?string
    {
        if (null === $reason) {
            return null;
        }

        $trimmed = trim($reason);

        if ('' === $trimmed) {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > 200) {
            throw new ValidationException(
                'Причина не може бути довшою за 200 символів.',
                'SUPPLIER_SUSPEND_REASON_TOO_LONG',
            );
        }

        return $trimmed;
    }
}
