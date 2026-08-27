<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Базовий виняток доменного шару.
 *
 * Несе машинний код помилки (`errorCode`) і HTTP-статус, з яких HTTP-шар
 * складає відповідь RFC 7807 (application/problem+json) з розширеннями
 * `code` і `requestId`.
 */
abstract class DomainException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly string $title,
    ) {
        parent::__construct($message);
    }

    /** Машинний код помилки, напр. VEHICLE_PLATE_DUPLICATE. */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** Короткий заголовок проблеми (поле `title` RFC 7807). */
    public function title(): string
    {
        return $this->title;
    }

    /** HTTP-статус, який відповідає цьому класу помилок. */
    abstract public function httpStatus(): int;
}
