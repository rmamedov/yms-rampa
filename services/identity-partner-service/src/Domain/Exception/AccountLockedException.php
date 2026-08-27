<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * AUTH_ACCOUNT_LOCKED (423, розділ 3.7).
 *
 * AUTH-50: після 5 невдалих спроб поспіль логін блокується на 15 хвилин.
 * Блокування повертається незалежно від правильності пароля і однаково
 * поводиться для неіснуючого логіна.
 */
final class AccountLockedException extends AuthException
{
    public function __construct(public readonly int $retryAfterSeconds = 900)
    {
        parent::__construct('Забагато невдалих спроб. Обліковий запис тимчасово заблоковано, спробуйте через 15 хвилин.');
    }

    public function errorCode(): string
    {
        return 'AUTH_ACCOUNT_LOCKED';
    }

    public function httpStatus(): int
    {
        return 423;
    }

    public function extensions(): array
    {
        return ['retryAfter' => $this->retryAfterSeconds];
    }
}
