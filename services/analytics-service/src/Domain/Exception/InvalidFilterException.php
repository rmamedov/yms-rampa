<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Некоректні фільтри дашборда (ANL-10): непарсабельний період, from > to,
 * невідомий розріз, задовгий період.
 */
final class InvalidFilterException extends \InvalidArgumentException implements AnalyticsException
{
    public function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalidPeriod(string $detail): self
    {
        return new self($detail, 'ANALYTICS_INVALID_PERIOD');
    }

    public static function invalidDimension(string $detail): self
    {
        return new self($detail, 'ANALYTICS_INVALID_DIMENSION');
    }

    public static function periodTooLong(string $detail): self
    {
        return new self($detail, 'ANALYTICS_PERIOD_TOO_LONG');
    }

    public static function invalidEnum(string $detail): self
    {
        return new self($detail, 'ANALYTICS_INVALID_FILTER');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Некоректні параметри фільтра';
    }
}
