<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Базова доменна помилка.
 *
 * Несе машинний код помилки та HTTP-статус, з яких HTTP-шар будує
 * відповідь RFC 7807 (application/problem+json) з розширеннями
 * code і requestId.
 */
class DomainException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
