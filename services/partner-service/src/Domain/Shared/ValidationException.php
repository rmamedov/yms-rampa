<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Порушення бізнес-правила або формату вхідних даних — HTTP 422.
 */
final class ValidationException extends DomainException
{
    public function __construct(string $message, string $errorCode = 'VALIDATION_FAILED')
    {
        parent::__construct($message, $errorCode, 'Некоректні дані');
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
