<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Clock\Clock;

/**
 * Зафіксований годинник для тестів і відтворюваних перерахунків.
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = 'now')
    {
        $this->now = $now instanceof \DateTimeImmutable
            ? $now->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }
}
