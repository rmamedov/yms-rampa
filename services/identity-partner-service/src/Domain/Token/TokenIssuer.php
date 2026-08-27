<?php

declare(strict_types=1);

namespace App\Domain\Token;

use App\Domain\Account\PartnerAccount;

/**
 * Випуск access-токенів партнерського контуру.
 *
 * AUTH-02: підпис виконується ВЛАСНОЮ ключовою парою partner-контуру;
 * приватний ключ ніколи не залишає цей сервіс (AUTH-64).
 */
interface TokenIssuer
{
    public function issueAccessToken(PartnerAccount $account, string $sid): IssuedAccessToken;
}
