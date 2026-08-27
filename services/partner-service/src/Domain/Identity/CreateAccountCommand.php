<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Команда створення креденшлів у identity-partner-service (DATA-35).
 */
final readonly class CreateAccountCommand
{
    public function __construct(
        public string $login,
        public string $password,
        public PartnerRole $role,
        public string $supplierId,
        public ?string $driverProfileId = null,
        public bool $mustChangePassword = true,
    ) {
    }
}
