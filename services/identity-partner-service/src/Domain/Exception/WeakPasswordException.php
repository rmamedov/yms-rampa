<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * AUTH_WEAK_PASSWORD (422, розділ 3.7).
 *
 * AUTH-13 / AUTH-21: пароль, що не відповідає політиці, відхиляється разом із
 * переліком порушених правил; кожне правило — окремий текст українською.
 */
final class WeakPasswordException extends AuthException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Пароль не відповідає вимогам безпеки.');
    }

    public function errorCode(): string
    {
        return 'AUTH_WEAK_PASSWORD';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function extensions(): array
    {
        return ['violations' => $this->violations];
    }
}
