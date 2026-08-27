<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Fact\BookingFact;

/**
 * Лічильники вибірки бронювань: ANL-02 (поставки по постачальниках за
 * статусами), ANL-03 (кількість no_show), ANL-05 (затримки та розподіл причин),
 * а також окремі розрізи за типом (walk_in vs scheduled) і причинами відмов.
 */
final readonly class BookingCounters
{
    /**
     * @param array<string, int> $byStatus
     * @param array<string, int> $byType
     * @param array<string, int> $byRejectionReason
     * @param array<string, int> $byDelayReason
     */
    public function __construct(
        public int $total,
        public array $byStatus,
        public array $byType,
        public array $byRejectionReason,
        public array $byDelayReason,
        public int $delayedCount,
        public int $partialUnloadCount,
        public int $plannedPallets,
        public int $unloadedPallets,
    ) {
    }

    /**
     * @param iterable<BookingFact> $facts
     */
    public static function fromFacts(iterable $facts): self
    {
        $byStatus = array_fill_keys(
            array_map(static fn (BookingStatus $s): string => $s->value, BookingStatus::cases()),
            0,
        );
        $byType = array_fill_keys(
            array_map(static fn (BookingType $t): string => $t->value, BookingType::cases()),
            0,
        );
        $byRejectionReason = [];
        $byDelayReason = [];
        $total = 0;
        $delayed = 0;
        $partial = 0;
        $planned = 0;
        $unloaded = 0;

        foreach ($facts as $fact) {
            ++$total;
            ++$byStatus[$fact->status()->value];
            ++$byType[$fact->type->value];
            $planned += $fact->palletsCount;
            $unloaded += $fact->unloadedPalletsCount() ?? 0;

            if ($fact->isPartialUnload()) {
                ++$partial;
            }

            if ($fact->isDelayed()) {
                ++$delayed;
                $reason = $fact->delayReason() ?? 'unspecified';
                $byDelayReason[$reason] = ($byDelayReason[$reason] ?? 0) + 1;
            }

            $rejection = $fact->rejectedReason();
            if ($rejection !== null) {
                $byRejectionReason[$rejection->value] = ($byRejectionReason[$rejection->value] ?? 0) + 1;
            }
        }

        ksort($byRejectionReason);
        ksort($byDelayReason);

        return new self(
            total: $total,
            byStatus: $byStatus,
            byType: $byType,
            byRejectionReason: $byRejectionReason,
            byDelayReason: $byDelayReason,
            delayedCount: $delayed,
            partialUnloadCount: $partial,
            plannedPallets: $planned,
            unloadedPallets: $unloaded,
        );
    }

    public function status(BookingStatus $status): int
    {
        return $this->byStatus[$status->value] ?? 0;
    }

    public function type(BookingType $type): int
    {
        return $this->byType[$type->value] ?? 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'byStatus' => $this->byStatus,
            'byType' => $this->byType,
            'byRejectionReason' => $this->byRejectionReason,
            'byDelayReason' => $this->byDelayReason,
            'delayedCount' => $this->delayedCount,
            'partialUnloadCount' => $this->partialUnloadCount,
            'plannedPallets' => $this->plannedPallets,
            'unloadedPallets' => $this->unloadedPallets,
        ];
    }
}
