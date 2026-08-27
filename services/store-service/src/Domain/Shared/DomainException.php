<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Базова доменна помилка. Кожна помилка несе машинний код і HTTP-статус,
 * з яких інфраструктурний шар будує відповідь RFC 7807 (application/problem+json).
 */
abstract class DomainException extends \RuntimeException
{
    /**
     * @param array<string, string> $details поле → повідомлення українською (для інлайн-помилок, UI-04)
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    abstract public function httpStatus(): int;

    abstract public function title(): string;

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, string> */
    public function details(): array
    {
        return $this->details;
    }
}
