<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Fact\BookingFact;
use App\Domain\Fact\BookingFactRepository;

/**
 * Реалізація сховища фактів у памʼяті: використовується юніт-тестами
 * (працюють без MongoDB і Redis) та dev-режимом без піднятої бази.
 */
final class InMemoryBookingFactRepository implements BookingFactRepository
{
    /** @var array<string, BookingFact> */
    private array $facts = [];

    /**
     * @param iterable<BookingFact> $facts
     */
    public function __construct(iterable $facts = [])
    {
        foreach ($facts as $fact) {
            $this->save($fact);
        }
    }

    public function findByBookingId(string $bookingId): ?BookingFact
    {
        return $this->facts[$bookingId] ?? null;
    }

    public function save(BookingFact $fact): void
    {
        $this->facts[$fact->bookingId] = $fact;
    }

    public function findByQuery(AnalyticsQuery $query): array
    {
        return array_values(array_filter(
            $this->facts,
            static fn (BookingFact $fact): bool => $query->matchesFact($fact),
        ));
    }

    public function lastUpdatedAt(): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->facts as $fact) {
            if ($last === null || $fact->updatedAt() > $last) {
                $last = $fact->updatedAt();
            }
        }

        return $last;
    }

    public function countAll(): int
    {
        return count($this->facts);
    }

    /**
     * @return list<BookingFact>
     */
    public function all(): array
    {
        return array_values($this->facts);
    }

    public function clear(): void
    {
        $this->facts = [];
    }
}
