<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Domain\Identity\CreateAccountCommand;
use App\Domain\Identity\PartnerAccountGateway;
use App\Domain\Shared\IdGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Тимчасова заглушка шлюзу до identity-partner-service.
 *
 * Поки контур ідентичності не піднято, вона лише генерує `accountId`
 * і пише намір у лог. Пароль у лог НЕ потрапляє за жодних обставин
 * (DATA-21, DATA-35).
 *
 * Замінити на HTTP/RabbitMQ-адаптер: команда створення акаунта в
 * identity-partner-service (`partner_accounts`) з unique `{login:1}`.
 */
final readonly class StubPartnerAccountGateway implements PartnerAccountGateway
{
    private LoggerInterface $logger;

    public function __construct(
        private IdGenerator $ids,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function createAccount(CreateAccountCommand $command): string
    {
        $accountId = $this->ids->generate();

        $this->logger->info('Заглушка identity-partner-service: створення акаунта', [
            'accountId' => $accountId,
            'login' => $command->login,
            'role' => $command->role->value,
            'supplierId' => $command->supplierId,
            'driverProfileId' => $command->driverProfileId,
        ]);

        return $accountId;
    }

    public function resetPassword(string $accountId, string $newPassword): void
    {
        $this->logger->info('Заглушка identity-partner-service: скидання пароля', [
            'accountId' => $accountId,
        ]);
    }

    public function setAccountActive(string $accountId, bool $active): void
    {
        $this->logger->info('Заглушка identity-partner-service: зміна активності акаунта', [
            'accountId' => $accountId,
            'active' => $active,
        ]);
    }

    public function setSupplierAccountsActive(string $supplierId, bool $active): int
    {
        $this->logger->info('Заглушка identity-partner-service: масова зміна активності акаунтів', [
            'supplierId' => $supplierId,
            'active' => $active,
        ]);

        return 0;
    }
}
