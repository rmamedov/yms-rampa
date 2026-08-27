<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Shared\Clock;

/**
 * Керований годинник для тестів і dev-сценаріїв (перемотування часу
 * у сценаріях блокування логіну та закінчення терміну дії токенів).
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string|\DateTimeImmutable $now = '2026-08-27T09:00:00+00:00')
    {
        $this->now = self::normalize($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function setTo(string|\DateTimeImmutable $moment): void
    {
        $this->now = self::normalize($moment);
    }

    /**
     * @param string $modifier модифікатор у форматі DateTime, напр. '+16 minutes'
     */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    /**
     * DATA-01: час завжди зберігається в UTC, навіть якщо рядок містив зсув.
     */
    private static function normalize(string|\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $value = $moment instanceof \DateTimeImmutable ? $moment : new \DateTimeImmutable($moment);

        return $value->setTimezone(new \DateTimeZone('UTC'));
    }
}
