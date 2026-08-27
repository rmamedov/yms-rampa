<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Shared\Clock;

/**
 * Годинник із фіксованим часом — робить доменні тести детермінованими.
 * DATA-01: час завжди в UTC.
 */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $isoTime = '2026-08-27T09:00:00+00:00')
    {
        $this->now = new \DateTimeImmutable($isoTime, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function set(string $isoTime): void
    {
        $this->now = new \DateTimeImmutable($isoTime, new \DateTimeZone('UTC'));
    }
}
