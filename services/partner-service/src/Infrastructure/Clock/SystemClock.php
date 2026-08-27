<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Domain\Shared\Clock;

/**
 * Системний годинник. DATA-01: усі мітки часу формуються в UTC;
 * у Europe/Kyiv їх переводить лише API/фронтенд.
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
