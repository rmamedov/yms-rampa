<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Базова доменна помилка.
 *
 * RBAC-33: кожна помилка транспортується як RFC 7807 `application/problem+json`
 * з розширеннями `code` і `requestId`. Саме тому доменна помилка несе
 * машинний код, HTTP-статус і готовий текст для користувача українською
 * (AUTH-53: текст не розкриває існування облікового запису чи стан 2FA).
 */
abstract class DomainException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context додаткові дані для тіла проблеми (напр. перелік порушених правил)
     */
    public function __construct(
        private readonly string $errorCode,
        private readonly int $httpStatus,
        private readonly string $userMessage,
        private readonly array $context = [],
    ) {
        parent::__construct($userMessage);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Текст для користувача українською (поле `detail` у RFC 7807).
     */
    public function userMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
