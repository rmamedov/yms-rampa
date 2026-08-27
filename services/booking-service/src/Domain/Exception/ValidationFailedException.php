<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Порушення простого правила валідації запиту, для якого специфікація
 * не закріпила окремого коду.
 */
final class ValidationFailedException extends ProblemException
{
    public const string ERROR_CODE = 'VALIDATION_FAILED';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function problemExtensions(): array
    {
        return [] === $this->details ? [] : ['details' => $this->details];
    }
}
