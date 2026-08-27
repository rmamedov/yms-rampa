<?php

declare(strict_types=1);

namespace App\Domain\Transport;

/**
 * Технічний збій провайдера (мережа, 5xx, таймаут).
 *
 * Такі помилки підлягають ретраю за NOT-04. Помилка, після якої ретрай
 * безглуздий (невалідний номер, чорний список), позначається
 * `retryable: false` — сповіщення одразу переходить у failed.
 */
class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public static function permanent(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, false, $previous);
    }
}
