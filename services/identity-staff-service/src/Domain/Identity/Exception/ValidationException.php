<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Shared\DomainException;

/**
 * Порушення формату вхідних даних (некоректний email, порожній ідентифікатор тощо).
 * Формат тіла — RFC 7807 (RBAC-33).
 */
final class ValidationException extends DomainException
{
    /**
     * @param list<string> $violations перелік порушених правил українською
     */
    public function __construct(string $message, array $violations = [])
    {
        parent::__construct(
            'VALIDATION_FAILED',
            422,
            $message,
            ['violations' => array_values($violations)],
        );
    }
}
