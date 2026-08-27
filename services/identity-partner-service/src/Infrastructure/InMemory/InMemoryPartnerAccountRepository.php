<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Account\PartnerAccount;
use App\Domain\Account\PartnerAccountRepository;
use App\Domain\Exception\LoginAlreadyTakenException;

/**
 * Реалізація `partner_accounts` у памʼяті.
 *
 * Використовується юніт-тестами і dev-режимом без MongoDB. Емулює унікальний
 * індекс `{login:1}` (10.6), кидаючи LoginAlreadyTakenException — так само, як
 * це зробив би Mongo з duplicate key.
 */
final class InMemoryPartnerAccountRepository implements PartnerAccountRepository
{
    /** @var array<string, PartnerAccount> */
    private array $accounts = [];

    public function findById(string $id): ?PartnerAccount
    {
        return $this->accounts[$id] ?? null;
    }

    public function findByLogin(string $login): ?PartnerAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->login() === $login) {
                return $account;
            }
        }

        return null;
    }

    public function findBySupplierId(string $supplierId): array
    {
        return array_values(array_filter(
            $this->accounts,
            static fn (PartnerAccount $account): bool => $account->supplierId === $supplierId,
        ));
    }

    public function save(PartnerAccount $account): void
    {
        $existing = $this->findByLogin($account->login());

        if (null !== $existing && $existing->id !== $account->id) {
            throw new LoginAlreadyTakenException($account->login());
        }

        $this->accounts[$account->id] = $account;
    }

    /** @return list<PartnerAccount> */
    public function all(): array
    {
        return array_values($this->accounts);
    }

    public function clear(): void
    {
        $this->accounts = [];
    }
}
