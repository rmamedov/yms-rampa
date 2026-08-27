<?php

declare(strict_types=1);

namespace App\Domain\Password;

use App\Domain\Shared\DomainException;

/**
 * AUTH-13: пароль, що не відповідає політиці, відхиляється з переліком
 * порушених правил — кожне правило окремим текстом українською.
 */
final class WeakPasswordException extends DomainException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct(
            'AUTH_WEAK_PASSWORD',
            422,
            'Пароль не відповідає вимогам безпеки: '.implode('; ', $violations).'.',
            ['violations' => array_values($violations)],
        );
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
