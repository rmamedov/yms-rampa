<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use App\Domain\Account\PartnerAccount;
use App\Domain\Account\PartnerAccountRepository;
use App\Domain\Account\PartnerRole;
use App\Domain\Clock\Clock;
use App\Domain\Exception\AccountNotFoundException;
use App\Domain\Exception\LoginAlreadyTakenException;
use App\Domain\Security\DriverPasswordGenerator;
use App\Domain\Security\LoginNormalizer;
use App\Domain\Security\PasswordHasher;
use App\Domain\Security\SecretGenerator;
use App\Domain\Security\SupplierPasswordPolicy;
use App\Domain\Session\RefreshTokenRepository;

/**
 * Створення та обслуговування креденшлів партнерського контуру.
 *
 * Єдина точка входу і для внутрішнього HTTP-ендпоїнта (виклик partner-service),
 * і для консольних команд (AUTH-20, AUTH-23, AUTH-24, AUTH-25, AUTH-28).
 */
final readonly class PartnerAccountProvisioner
{
    public function __construct(
        private PartnerAccountRepository $accounts,
        private RefreshTokenRepository $refreshTokens,
        private PasswordHasher $passwordHasher,
        private LoginNormalizer $loginNormalizer,
        private DriverPasswordGenerator $driverPasswords,
        private SupplierPasswordPolicy $passwordPolicy,
        private SecretGenerator $secrets,
        private Clock $clock,
    ) {
    }

    /**
     * AUTH-23: логін нормалізується перед збереженням — телефон `067 123 45 67`
     * лягає в `partner_accounts` як `+380671234567`.
     * AUTH-24: для водія без явного пароля генерується одноразовий пароль.
     *
     * @throws LoginAlreadyTakenException унікальний індекс `{login:1}` (10.6)
     * @throws \App\Domain\Exception\InvalidLoginFormatException
     * @throws \App\Domain\Exception\WeakPasswordException пароль постачальника слабкий (AUTH-21)
     */
    public function create(CreatePartnerAccount $command): IssuedCredentials
    {
        $login = $this->loginNormalizer->normalizeForRole($command->role, $command->login);

        if (null !== $this->accounts->findByLogin($login)) {
            throw new LoginAlreadyTakenException($login);
        }

        $generated = null === $command->passwordPlain;

        if ($generated) {
            $passwordPlain = $this->driverPasswords->generate();
        } else {
            $passwordPlain = $command->passwordPlain;
            \assert(null !== $passwordPlain);

            // Політика паролів AUTH-21 стосується самостійно заданих паролів
            // партнерів; згенеровані системою паролі перевіряти немає сенсу.
            $this->passwordPolicy->assertValid($passwordPlain, $login);
        }

        $now = $this->clock->now();

        $account = new PartnerAccount(
            id: $this->secrets->newId(),
            login: $login,
            passwordHash: $this->passwordHasher->hash($passwordPlain),
            role: $command->role,
            supplierId: $command->supplierId,
            driverProfileId: $command->driverProfileId,
            active: $command->active,
            // DATA (10.6): mustChangePassword = true після генерації пароля
            // системою (приклад документа водія в специфікації саме такий).
            mustChangePassword: $generated,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->accounts->save($account);

        return new IssuedCredentials(
            profile: $account->profile(),
            passwordPlain: $generated ? $passwordPlain : null,
            passwordGenerated: $generated,
        );
    }

    /**
     * AUTH-25: перегенерація пароля водія постачальником.
     *
     * Старий пароль стає недійсним негайно, всі сесії водія відкликаються,
     * новий пароль повертається рівно один раз (для модального вікна
     * supplier-web і SMS від notification-service).
     */
    public function regeneratePassword(string $accountId): IssuedCredentials
    {
        $account = $this->accounts->findById($accountId);

        if (null === $account) {
            throw new AccountNotFoundException($accountId);
        }

        $now = $this->clock->now();
        $passwordPlain = $this->driverPasswords->generate();

        $account->changePasswordHash(
            passwordHash: $this->passwordHasher->hash($passwordPlain),
            at: $now,
            mustChangePassword: true,
        );

        $this->accounts->save($account);
        $this->refreshTokens->revokeAllForAccount($account->id, $now);

        return new IssuedCredentials(
            profile: $account->profile(),
            passwordPlain: $passwordPlain,
            passwordGenerated: true,
        );
    }

    /**
     * AUTH-28: деактивація постачальника вимикає логін усім його користувачам
     * і водіям та відкликає активні refresh-токени.
     *
     * @return int кількість деактивованих акаунтів
     */
    public function suspendSupplier(string $supplierId): int
    {
        $now = $this->clock->now();
        $affected = 0;

        foreach ($this->accounts->findBySupplierId($supplierId) as $account) {
            if ($account->isActive()) {
                $account->deactivate($now);
                $this->accounts->save($account);
                ++$affected;
            }

            $this->refreshTokens->revokeAllForAccount($account->id, $now);
        }

        return $affected;
    }

    /** Зворотна операція до AUTH-28 (постачальника знову увімкнено). */
    public function resumeSupplier(string $supplierId): int
    {
        $now = $this->clock->now();
        $affected = 0;

        foreach ($this->accounts->findBySupplierId($supplierId) as $account) {
            if (!$account->isActive()) {
                $account->activate($now);
                $this->accounts->save($account);
                ++$affected;
            }
        }

        return $affected;
    }

    /**
     * Зміна телефону водія = зміна логіна; вимагає перегенерації пароля
     * (крайовий випадок 3.3.2).
     */
    public function changeDriverLogin(string $accountId, string $rawLogin): IssuedCredentials
    {
        $account = $this->accounts->findById($accountId);

        if (null === $account) {
            throw new AccountNotFoundException($accountId);
        }

        $login = $this->loginNormalizer->normalizeForRole($account->role, $rawLogin);
        $existing = $this->accounts->findByLogin($login);

        if (null !== $existing && $existing->id !== $account->id) {
            throw new LoginAlreadyTakenException($login);
        }

        $account->changeLogin($login, $this->clock->now());
        $this->accounts->save($account);

        return $this->regeneratePassword($account->id);
    }

    /** Ролі, які взагалі можуть існувати в цьому контурі (AUTH-29). */
    public function supportsRole(PartnerRole $role): bool
    {
        return $role->isDriver() || $role->isSupplierSide();
    }
}
