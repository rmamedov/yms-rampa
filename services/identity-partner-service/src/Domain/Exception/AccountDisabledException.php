<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * AUTH_ACCOUNT_DISABLED (403, розділ 3.7).
 *
 * AUTH-12: деактивований обліковий запис не проходить автентифікацію навіть
 * із правильним паролем. AUTH-28: деактивація постачальника вимикає всіх його
 * користувачів і водіїв.
 */
final class AccountDisabledException extends AuthException
{
    public function __construct()
    {
        parent::__construct('Обліковий запис деактивовано. Зверніться до адміністратора.');
    }

    public function errorCode(): string
    {
        return 'AUTH_ACCOUNT_DISABLED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
