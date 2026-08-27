<?php

declare(strict_types=1);

namespace App\Domain\Stats;

use App\Domain\Analytics\AnalyticsQuery;

interface DailyStoreStatsRepository
{
    public function save(DailyStoreStats $stats): void;

    /**
     * @param iterable<DailyStoreStats> $stats
     */
    public function saveMany(iterable $stats): void;

    public function find(string $storeId, string $date): ?DailyStoreStats;

    /**
     * Вибірка за фільтрами дашборда (період, місто, магазини).
     *
     * @return list<DailyStoreStats>
     */
    public function findByQuery(AnalyticsQuery $query): array;

    /** ANL-14: час останнього перерахунку read-моделі. */
    public function lastRecalculatedAt(): ?\DateTimeImmutable;
}
