<?php

declare(strict_types=1);

namespace App\Domain\Fact;

use App\Domain\Analytics\AnalyticsQuery;

/**
 * Сховище фактів бронювань. Доменний контракт без залежності від MongoDB:
 * реалізації — Infrastructure\Mongo (прод) та Infrastructure\InMemory (тести, dev).
 */
interface BookingFactRepository
{
    public function findByBookingId(string $bookingId): ?BookingFact;

    public function save(BookingFact $fact): void;

    /**
     * @return list<BookingFact>
     */
    public function findByQuery(AnalyticsQuery $query): array;

    /**
     * Час останнього перерахунку read-моделі (ANL-14, мітка recalculatedAt).
     */
    public function lastUpdatedAt(): ?\DateTimeImmutable;

    public function countAll(): int;
}
