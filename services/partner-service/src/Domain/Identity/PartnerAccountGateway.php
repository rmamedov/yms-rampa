<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Shared\ConflictException;

/**
 * Порт до identity-partner-service (DATA-35).
 *
 * partner-service ніколи не зберігає `passwordHash` — він лише просить
 * контур ідентичності створити акаунт, змінити пароль або заблокувати логін.
 * Реалізації: HttpPartnerAccountGateway у проді, InMemory — у тестах і dev.
 */
interface PartnerAccountGateway
{
    /**
     * @return string `accountId` створеного акаунта (`partner_accounts._id`)
     *
     * @throws ConflictException            якщо логін уже зайнятий (unique {login:1})
     * @throws IdentityUnavailableException якщо контур ідентичності не відповів
     */
    public function createAccount(CreateAccountCommand $command): string;

    /**
     * SUP-DRV-04: старий пароль інвалідовується, активні сесії завершуються.
     *
     * @param string $newPassword пароль, який ПРОПОНУЄ partner-service
     *
     * @return string пароль, який РЕАЛЬНО діє після виклику
     *
     * Повернений рядок — єдине джерело правди для SMS і для модалки
     * «Запишіть пароль». Він може відрізнятися від `$newPassword`: за AUTH-24
     * і AUTH-25 паролем водія володіє контур ідентичності, і його службовий
     * маршрут `/password/regenerate` генерує пароль сам. InMemory-реалізація
     * (dev, тести) поважає запропонований пароль і повертає саме його.
     *
     * @throws IdentityUnavailableException якщо контур ідентичності не відповів
     */
    public function resetPassword(string $accountId, string $newPassword): string;

    /**
     * SUP-DRV-05: `active=false` у `partner_accounts` = логін заборонено.
     *
     * @throws IdentityUnavailableException якщо контур ідентичності не відповів
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
