<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Clock\Clock;

/**
 * Керований годинник для тестів і dev-сценаріїв (перевірка TTL блокувань,
 * refresh-вікон 30/90 днів тощо) без залежності від реального часу.
 */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string|\DateTimeImmutable $now = '2026-08-27T09:00:00+00:00')
    {
        $this->now = $now instanceof \DateTimeImmutable
            ? $now->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string|\DateTimeImmutable $now): void
    {
        $this->now = $now instanceof \DateTimeImmutable
            ? $now->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    /** Наприклад: `+16 minutes`, `+91 days`. */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
