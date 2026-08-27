<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Подія з RabbitMQ не відповідає контракту (немає обовʼязкового поля,
 * непарсабельна дата, невідомий тип бронювання тощо).
 */
final class MalformedEventException extends \RuntimeException implements AnalyticsException
{
    public function __construct(string $message, private readonly string $errorCode = 'EVENT_MALFORMED')
    {
        parent::__construct($message);
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
        return 'Некоректна доменна подія';
    }
}
