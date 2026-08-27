<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Identity\CreateAccountCommand;
use App\Domain\Identity\PartnerAccountGateway;
use App\Domain\Identity\PartnerRole;
use App\Domain\Shared\ConflictException;

/**
 * Емуляція identity-partner-service у пам'яті (dev і тести).
 *
 * Повторює контракт розділу 10.6: unique `{login:1}`, рівно одна роль
 * на акаунт, `active=false` = логін заборонено. Паролі тут зберігаються
 * ЛИШЕ для перевірок у тестах — у справжньому контурі це argon2id-хеш,
 * і partner-service його не бачить ніколи (DATA-35).
 */
final class InMemoryPartnerAccountGateway implements PartnerAccountGateway
{
    /** @var array<string, array{id: string, login: string, password: string, role: PartnerRole, supplierId: string, driverProfileId: string|null, active: bool, mustChangePassword: bool}> */
    private array $accounts = [];

    private int $sequence = 0;

    public function createAccount(CreateAccountCommand $command): string
    {
        foreach ($this->accounts as $account) {
            if ($account['login'] === $command->login) {
                throw new ConflictException(
                    \sprintf('Логін «%s» уже зайнятий.', $command->login),
                    'ACCOUNT_LOGIN_DUPLICATE',
                );
            }
        }

        $id = \sprintf('pa-%04d', ++$this->sequence);

        $this->accounts[$id] = [
            'id' => $id,
            'login' => $command->login,
            'password' => $command->password,
            'role' => $command->role,
            'supplierId' => $command->supplierId,
            'driverProfileId' => $command->driverProfileId,
            'active' => true,
            'mustChangePassword' => $command->mustChangePassword,
        ];

        return $id;
    }

    public function resetPassword(string $accountId, string $newPassword): void
    {
        $this->requireAccount($accountId);
        $this->accounts[$accountId]['password'] = $newPassword;
        $this->accounts[$accountId]['mustChangePassword'] = true;
    }

    public function setAccountActive(string $accountId, bool $active): void
    {
        $this->requireAccount($accountId);
        $this->accounts[$accountId]['active'] = $active;
    }

    public function setSupplierAccountsActive(string $supplierId, bool $active): int
    {
        $affected = 0;

        foreach ($this->accounts as $id => $account) {
            if ($account['supplierId'] !== $supplierId) {
                continue;
            }

            $this->accounts[$id]['active'] = $active;
            ++$affected;
        }

        return $affected;
    }

    // --- допоміжні методи для тестів і dev-режиму ---

    /**
     * @return array{id: string, login: string, password: string, role: PartnerRole, supplierId: string, driverProfileId: string|null, active: bool, mustChangePassword: bool}|null
     */
    public function findByLogin(string $login): ?array
    {
        foreach ($this->accounts as $account) {
            if ($account['login'] === $login) {
                return $account;
            }
        }

        return null;
    }

    public function isActive(string $accountId): bool
    {
        return $this->accounts[$accountId]['active'] ?? false;
    }

    public function passwordOf(string $accountId): ?string
    {
        return $this->accounts[$accountId]['password'] ?? null;
    }

    public function count(): int
    {
        return \count($this->accounts);
    }

    private function requireAccount(string $accountId): void
    {
        if (!isset($this->accounts[$accountId])) {
            throw new ConflictException(
                \sprintf('Акаунт «%s» не знайдено в контурі ідентичності.', $accountId),
                'ACCOUNT_NOT_FOUND',
            );
        }
    }
}
