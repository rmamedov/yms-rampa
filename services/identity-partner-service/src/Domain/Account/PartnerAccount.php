<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Обліковий запис партнерського контуру — колекція `partner_accounts` (10.6).
 *
 * AUTH-29 / DATA-35: це ЄДИНЕ місце, де живуть креденшли постачальників і
 * водіїв. Бізнес-профілі (ПІБ, авто, контакти) зберігаються в partner-service
 * у колекції `partner_users` і НЕ містять passwordHash; звʼязок —
 * `driverProfileId` (акаунт → профіль) та `accountId` (профіль → акаунт).
 */
final class PartnerAccount
{
    public const int SCHEMA_VERSION = 1;

    private \DateTimeImmutable $updatedAt;

    public function __construct(
        public readonly string $id,
        private string $login,
        private string $passwordHash,
        public readonly PartnerRole $role,
        public readonly string $supplierId,
        private ?string $driverProfileId = null,
        private bool $active = true,
        private bool $mustChangePassword = false,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable('@0'),
        ?\DateTimeImmutable $updatedAt = null,
        private ?\DateTimeImmutable $lastLoginAt = null,
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ('' === trim($this->id)) {
            throw new \InvalidArgumentException('Ідентифікатор облікового запису не може бути порожнім.');
        }
        if ('' === trim($login)) {
            throw new \InvalidArgumentException('Логін облікового запису не може бути порожнім.');
        }
        if ('' === trim($this->supplierId)) {
            throw new \InvalidArgumentException('Поле supplierId обовʼязкове для облікового запису партнера.');
        }
        if ('' === trim($passwordHash)) {
            throw new \InvalidArgumentException('Хеш пароля не може бути порожнім.');
        }

        $this->updatedAt = $updatedAt ?? $this->createdAt;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function driverProfileId(): ?string
    {
        return $this->driverProfileId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Заміна пароля (перегенерація водієві — AUTH-25, або встановлення
     * постачальником свого пароля — AUTH-21).
     */
    public function changePasswordHash(string $passwordHash, \DateTimeImmutable $at, bool $mustChangePassword = false): void
    {
        if ('' === trim($passwordHash)) {
            throw new \InvalidArgumentException('Хеш пароля не може бути порожнім.');
        }

        $this->passwordHash = $passwordHash;
        $this->mustChangePassword = $mustChangePassword;
        $this->touch($at);
    }

    /**
     * Оновлення хеша без зміни пароля — автоматичний rehash після посилення
     * параметрів argon2id (AUTH-60).
     */
    public function rehash(string $passwordHash, \DateTimeImmutable $at): void
    {
        $this->passwordHash = $passwordHash;
        $this->touch($at);
    }

    /**
     * Зміна номера телефону водія = зміна логіна (крайовий випадок 3.3.2:
     * вимагає перегенерації пароля).
     */
    public function changeLogin(string $login, \DateTimeImmutable $at): void
    {
        if ('' === trim($login)) {
            throw new \InvalidArgumentException('Логін облікового запису не може бути порожнім.');
        }

        $this->login = $login;
        $this->touch($at);
    }

    /** AUTH-12 / AUTH-28: деактивований акаунт не проходить автентифікацію. */
    public function deactivate(\DateTimeImmutable $at): void
    {
        $this->active = false;
        $this->touch($at);
    }

    public function activate(\DateTimeImmutable $at): void
    {
        $this->active = true;
        $this->touch($at);
    }

    public function attachDriverProfile(string $driverProfileId, \DateTimeImmutable $at): void
    {
        $this->driverProfileId = $driverProfileId;
        $this->touch($at);
    }

    public function markLoggedIn(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
        $this->touch($at);
    }

    /** Профіль, який віддається клієнту після логіну (без passwordHash). */
    public function profile(): AccountProfile
    {
        return new AccountProfile(
            accountId: $this->id,
            login: $this->login,
            role: $this->role,
            supplierId: $this->supplierId,
            driverProfileId: $this->driverProfileId,
            mustChangePassword: $this->mustChangePassword,
        );
    }

    private function touch(\DateTimeImmutable $at): void
    {
        $this->updatedAt = $at;
    }
}
