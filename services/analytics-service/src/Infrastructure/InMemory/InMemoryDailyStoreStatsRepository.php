<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\PeriodBucket;
use App\Domain\Stats\DailyStoreStats;
use App\Domain\Stats\DailyStoreStatsRepository;

/**
 * Агрегат «магазин × доба» у памʼяті.
 */
final class InMemoryDailyStoreStatsRepository implements DailyStoreStatsRepository
{
    /** @var array<string, DailyStoreStats> */
    private array $stats = [];

    public function save(DailyStoreStats $stats): void
    {
        $this->stats[$stats->id()] = $stats;
    }

    public function saveMany(iterable $stats): void
    {
        foreach ($stats as $item) {
            $this->save($item);
        }
    }

    public function find(string $storeId, string $date): ?DailyStoreStats
    {
        return $this->stats[$storeId . ':' . $date] ?? null;
    }

    public function findByQuery(AnalyticsQuery $query): array
    {
        $from = PeriodBucket::day($query->from);
        $to = PeriodBucket::day($query->to);

        $rows = array_values(array_filter(
            $this->stats,
            static function (DailyStoreStats $item) use ($query, $from, $to): bool {
                if ($item->date < $from || $item->date > $to) {
                    return false;
                }
                if ($query->cities !== [] && !in_array($item->city, $query->cities, true)) {
                    return false;
                }
                if ($query->storeIds !== [] && !in_array($item->storeId, $query->storeIds, true)) {
                    return false;
                }

                return true;
            },
        ));

        usort($rows, static fn (DailyStoreStats $a, DailyStoreStats $b): int => [$a->date, $a->storeId] <=> [$b->date, $b->storeId]);

        return $rows;
    }

    public function lastRecalculatedAt(): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->stats as $item) {
            if ($last === null || $item->recalculatedAt > $last) {
                $last = $item->recalculatedAt;
            }
        }

        return $last;
    }

    public function clear(): void
    {
        $this->stats = [];
    }
}
