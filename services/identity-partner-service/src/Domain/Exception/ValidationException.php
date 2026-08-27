<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/** Некоректне тіло запиту (відсутні або порожні обовʼязкові поля). */
final class ValidationException extends AuthException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Некоректні дані запиту.');
    }

    public static function missingField(string $field): self
    {
        return new self([\sprintf('Поле «%s» обовʼязкове.', $field)]);
    }

    public function errorCode(): string
    {
        return 'VALIDATION_FAILED';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Некоректні дані';
    }

    public function extensions(): array
    {
        return ['violations' => $this->violations];
    }
}
