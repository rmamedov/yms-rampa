<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Порушення доменних правил валідації → HTTP 422 (STC-20, STC-30, DATA-10, DATA-33).
 */
final class ValidationException extends DomainException
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Дані не пройшли перевірку';
    }

    /**
     * @param array<string, string> $details
     */
    public static function config(string $message, array $details = []): self
    {
        return new self($message, 'CONFIG_VALIDATION_FAILED', $details);
    }

    /**
     * @param array<string, string> $details
     */
    public static function field(string $field, string $message): self
    {
        return new self($message, 'VALIDATION_FAILED', [$field => $message]);
    }
}
