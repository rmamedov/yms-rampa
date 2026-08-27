<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Clock\Clock;

/**
 * Керований годинник для тестів і dev-сценаріїв.
 *
 * Дає змогу перевірити backoff (NOT-04) і планувальник нагадувань (NOT-06)
 * без реального очікування.
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

    /** Просуває годинник уперед на вказану кількість секунд. */
    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->add(new \DateInterval('PT'.$seconds.'S'));
    }

    public function advanceMinutes(int $minutes): void
    {
        $this->advanceSeconds($minutes * 60);
    }
}
