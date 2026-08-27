<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/** Обліковий запис не знайдено (внутрішні операції partner-service). */
final class AccountNotFoundException extends AuthException
{
    public function __construct(public readonly string $accountId)
    {
        parent::__construct('Обліковий запис не знайдено.');
    }

    public function errorCode(): string
    {
        return 'PARTNER_ACCOUNT_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function title(): string
    {
        return 'Обліковий запис не знайдено';
    }
}
