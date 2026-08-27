<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Domain\Clock\Clock;

/**
 * Системний годинник. Завжди повертає час у UTC (конвенція зберігання).
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
