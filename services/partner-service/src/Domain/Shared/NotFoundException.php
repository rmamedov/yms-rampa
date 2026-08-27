<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Сутність не знайдена або недоступна цьому власнику (тенанту) — HTTP 404.
 *
 * Свідомо не розрізняємо «не існує» і «чуже»: постачальник не повинен
 * дізнаватися про існування об'єктів інших постачальників.
 */
final class NotFoundException extends DomainException
{
    public function __construct(string $message, string $errorCode = 'NOT_FOUND')
    {
        parent::__construct($message, $errorCode, 'Не знайдено');
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
