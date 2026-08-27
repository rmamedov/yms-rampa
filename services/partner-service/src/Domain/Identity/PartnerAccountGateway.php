<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Shared\ConflictException;

/**
 * Порт до identity-partner-service (DATA-35).
 *
 * partner-service ніколи не зберігає `passwordHash` — він лише просить
 * контур ідентичності створити акаунт, змінити пароль або заблокувати логін.
 * Реалізації: HTTP/RabbitMQ-адаптер у проді, InMemory — у тестах і dev.
 */
interface PartnerAccountGateway
{
    /**
     * @return string `accountId` створеного акаунта (`partner_accounts._id`)
     *
     * @throws ConflictException якщо логін уже зайнятий (unique {login:1})
     */
    public function createAccount(CreateAccountCommand $command): string;

    /**
     * SUP-DRV-04: старий пароль інвалідовується, активні сесії завершуються.
     */
    public function resetPassword(string $accountId, string $newPassword): void;

    /**
     * SUP-DRV-05: `active=false` у `partner_accounts` = логін заборонено.
     */
    public function setAccountActive(string $accountId, bool $active): void;

    /**
     * SUP-02: масове блокування логінів усіх акаунтів постачальника
     * (індекс `{supplierId:1}` у `partner_accounts`).
     *
     * @return int кількість зачеплених акаунтів
     */
    public function setSupplierAccountsActive(string $supplierId, bool $active): int;
}
