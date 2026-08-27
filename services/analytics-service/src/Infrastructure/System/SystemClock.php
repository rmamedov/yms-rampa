<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\Clock\Clock;

/**
 * Системний годинник: усі мітки часу — в UTC (конвенція зберігання).
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
