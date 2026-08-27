<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Stats\DailyStoreStats;

/**
 * Мапер DailyStoreStats ↔ документ MongoDB (колекція daily_store_stats).
 */
final readonly class DailyStoreStatsDocumentMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toDocument(DailyStoreStats $stats): array
    {
        return [
            '_id' => $stats->id(),
            'date' => $stats->date,
            'storeId' => $stats->storeId,
            'city' => $stats->city,
            'bookingsTotal' => $stats->bookingsTotal,
            'completedCount' => $stats->completedCount,
            'cancelledCount' => $stats->cancelledCount,
            'noShowCount' => $stats->noShowCount,
            'rejectedCount' => $stats->rejectedCount,
            'walkInCount' => $stats->walkInCount,
            'scheduledCount' => $stats->scheduledCount,
            'delayedCount' => $stats->delayedCount,
            'plannedPallets' => $stats->plannedPallets,
            'unloadedPallets' => $stats->unloadedPallets,
            'bookedMinutes' => $stats->bookedMinutes,
            'availableMinutes' => $stats->availableMinutes,
            'utilizationPercent' => $stats->utilizationPercent,
            'onTimePercent' => $stats->onTimePercent,
            'waitingAverageMinutes' => $stats->waitingAverageMinutes,
            'waitingMedianMinutes' => $stats->waitingMedianMinutes,
            'unloadingAverageMinutes' => $stats->unloadingAverageMinutes,
            'unloadingMedianMinutes' => $stats->unloadingMedianMinutes,
            'noShowPercent' => $stats->noShowPercent,
            'recalculatedAt' => $stats->recalculatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public function fromDocument(array $document): DailyStoreStats
    {
        return new DailyStoreStats(
            date: (string) $document['date'],
            storeId: (string) $document['storeId'],
            city: (string) $document['city'],
            bookingsTotal: (int) ($document['bookingsTotal'] ?? 0),
            completedCount: (int) ($document['completedCount'] ?? 0),
            cancelledCount: (int) ($document['cancelledCount'] ?? 0),
            noShowCount: (int) ($document['noShowCount'] ?? 0),
            rejectedCount: (int) ($document['rejectedCount'] ?? 0),
            walkInCount: (int) ($document['walkInCount'] ?? 0),
            scheduledCount: (int) ($document['scheduledCount'] ?? 0),
            delayedCount: (int) ($document['delayedCount'] ?? 0),
            plannedPallets: (int) ($document['plannedPallets'] ?? 0),
            unloadedPallets: (int) ($document['unloadedPallets'] ?? 0),
            bookedMinutes: (float) ($document['bookedMinutes'] ?? 0.0),
            availableMinutes: (float) ($document['availableMinutes'] ?? 0.0),
            utilizationPercent: self::nullableFloat($document['utilizationPercent'] ?? null),
            onTimePercent: self::nullableFloat($document['onTimePercent'] ?? null),
            waitingAverageMinutes: self::nullableFloat($document['waitingAverageMinutes'] ?? null),
            waitingMedianMinutes: self::nullableFloat($document['waitingMedianMinutes'] ?? null),
            unloadingAverageMinutes: self::nullableFloat($document['unloadingAverageMinutes'] ?? null),
            unloadingMedianMinutes: self::nullableFloat($document['unloadingMedianMinutes'] ?? null),
            noShowPercent: self::nullableFloat($document['noShowPercent'] ?? null),
            recalculatedAt: BsonCodec::requireDate($document['recalculatedAt'] ?? null, 'recalculatedAt'),
        );
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
