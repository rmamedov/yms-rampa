<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Зафіксований годинник для тестів і для відтворюваних імпортів.
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = 'now')
    {
        $this->now = \is_string($now)
            ? new \DateTimeImmutable($now, new \DateTimeZone('UTC'))
            : $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /** Просунути годинник уперед (наприклад, на добу між синхронізаціями). */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }
}
