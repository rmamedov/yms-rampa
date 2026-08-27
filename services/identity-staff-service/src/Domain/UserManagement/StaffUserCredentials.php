<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use App\Domain\Identity\StaffUser;

/**
 * Обліковий запис разом із паролем, який показується РІВНО ОДИН РАЗ
 * (створення акаунта і перегенерація пароля, розділ 4.7).
 *
 * AUTH-61: у сховищі лишається тільки argon2id-хеш, тому повторно
 * показати пароль неможливо — саме тому він живе в окремому обʼєкті,
 * який не є частиною агрегату і нікуди не зберігається.
 */
final readonly class StaffUserCredentials
{
    /**
     * Текст, який адмін-панель показує поруч із паролем. Формулювання
     * спільне з кабінетом постачальника (SUP-DRV-03).
     */
    public const string NOTICE = 'Запишіть пароль — повторно він не показується.';

    public function __construct(
        public StaffUser $user,
        public string $password,
    ) {
    }
}
