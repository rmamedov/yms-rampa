<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Зупинений годинник для тестів і демо-сценаріїв: час рухається лише
 * явними викликами.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now = 'now')
    {
        $this->now = \is_string($now)
            ? new DateTimeImmutable($now, new DateTimeZone('UTC'))
            : $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable|string $now): void
    {
        $this->now = \is_string($now)
            ? new DateTimeImmutable($now, new DateTimeZone('UTC'))
            : $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
