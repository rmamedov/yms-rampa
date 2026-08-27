<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Domain\Clock\Clock;

/** Системний годинник; завжди UTC (DATA-01). */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
