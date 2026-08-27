<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use App\Domain\Account\PartnerRole;

/**
 * Команда створення облікового запису партнерського контуру (AUTH-20, AUTH-23).
 *
 * Приходить від partner-service — через RabbitMQ або синхронний внутрішній
 * REST-виклик (DATA-35), а також із консолі (`app:partner-account:create`).
 *
 * `passwordPlain = null` означає «згенеруй пароль сам» — саме так створюється
 * водій (AUTH-24).
 */
final readonly class CreatePartnerAccount
{
    public function __construct(
        public string $login,
        public PartnerRole $role,
        public string $supplierId,
        public ?string $passwordPlain = null,
        public ?string $driverProfileId = null,
        public bool $active = true,
    ) {
    }
}
