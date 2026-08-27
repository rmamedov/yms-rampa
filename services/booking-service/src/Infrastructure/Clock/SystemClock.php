<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Domain\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Системний годинник. Завжди повертає час в UTC — зберігання в UTC,
 * локальна зона магазину застосовується лише при відображенні.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
