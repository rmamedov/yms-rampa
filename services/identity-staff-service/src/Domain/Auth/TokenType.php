<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Тип токена: клейм `typ` дозволяє відрізнити access від refresh
 * і не приймати refresh-токен там, де очікується access (розділ 3.4).
 */
enum TokenType: string
{
    case Access = 'access';
    case Refresh = 'refresh';
    case TwoFactorChallenge = '2fa_challenge';
}
