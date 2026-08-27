<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Контракт доменної помилки analytics-service.
 *
 * Кожна помилка несе машинний код і HTTP-статус, з яких HTTP-шар будує
 * відповідь RFC 7807 application/problem+json з розширеннями code і requestId.
 */
interface AnalyticsException extends \Throwable
{
    /** Машинний код помилки, напр. ANALYTICS_INVALID_PERIOD. */
    public function errorCode(): string;

    public function httpStatus(): int;

    /** Заголовок помилки українською (поле title RFC 7807). */
    public function title(): string;
}
