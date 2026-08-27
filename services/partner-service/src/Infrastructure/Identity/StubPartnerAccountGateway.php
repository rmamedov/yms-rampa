<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Domain\Identity\CreateAccountCommand;
use App\Domain\Identity\PartnerAccountGateway;
use App\Domain\Shared\IdGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Заглушка шлюзу до identity-partner-service.
 *
 * Вона лише генерує `accountId` і пише намір у лог. Пароль у лог НЕ потрапляє
 * за жодних обставин (DATA-21, DATA-35).
 *
 * УВАГА: у ПРОДІ цей клас НЕ використовується — там працює
 * {@see HttpPartnerAccountGateway} (config/packages/prod/upstream.yaml).
 * Заглушка мовчки «створює» акаунт, якого насправді немає: профіль водія
 * зберігається, а увійти він не може (401). Тримаємо її лише як
 * діагностичний інструмент для локальних сценаріїв без контуру ідентичності.
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

    public function resetPassword(string $accountId, string $newPassword): string
    {
        $this->logger->info('Заглушка identity-partner-service: скидання пароля', [
            'accountId' => $accountId,
        ]);

        return $newPassword;
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
