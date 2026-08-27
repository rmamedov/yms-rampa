<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Тип користувача партнерського контуру для сесійних артефактів
 * (`refresh_tokens.userType`, розділ 10.6).
 */
enum UserType: string
{
    case Supplier = 'supplier';
    case Driver = 'driver';
}
