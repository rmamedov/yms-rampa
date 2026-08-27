<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Профіль облікового запису, який повертається клієнту після логіну.
 *
 * AUTH-61 / DATA-35: passwordHash сюди не потрапляє ніколи.
 */
final readonly class AccountProfile
{
    public function __construct(
        public string $accountId,
        public string $login,
        public PartnerRole $role,
        public string $supplierId,
        public ?string $driverProfileId,
        public bool $mustChangePassword,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accountId' => $this->accountId,
            'login' => $this->login,
            'role' => $this->role->value,
            'contour' => Contour::Partner->value,
            'supplierId' => $this->supplierId,
            'driverId' => $this->driverProfileId,
            'mustChangePassword' => $this->mustChangePassword,
        ];
    }
}
