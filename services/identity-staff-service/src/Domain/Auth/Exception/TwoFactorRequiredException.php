<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * AUTH-17 / таблиця 3.7: 401 AUTH_2FA_REQUIRED.
 *
 * Після валідної пари email+пароль сервіс повертає короткоживучий (5 хв)
 * challenge-токен; другий запит містить challenge-токен + TOTP-код.
 */
final class TwoFactorRequiredException extends DomainException
{
    public function __construct(private readonly string $challengeToken, \DateTimeImmutable $expiresAt)
    {
        parent::__construct(
            'AUTH_2FA_REQUIRED',
            401,
            'Введіть код з застосунку-автентифікатора.',
            ['challengeToken' => $challengeToken, 'challengeExpiresAt' => $expiresAt->format(\DATE_ATOM)],
        );
    }

    public function challengeToken(): string
    {
        return $this->challengeToken;
    }
}
