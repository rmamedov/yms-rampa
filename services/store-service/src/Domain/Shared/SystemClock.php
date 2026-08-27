<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Системний годинник. Завжди повертає час у UTC (DATA-01).
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
