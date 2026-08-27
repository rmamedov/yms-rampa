<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Конфлікт стану: дублікат унікального ключа, спроба видалити сутність
 * з історією тощо — HTTP 409.
 */
final class ConflictException extends DomainException
{
    public function __construct(string $message, string $errorCode = 'CONFLICT')
    {
        parent::__construct($message, $errorCode, 'Конфлікт стану');
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
