<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kpi;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;
use App\Domain\Kpi\BookingCounters;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ANL-02 (поставки за статусами), ANL-03 (no_show), ANL-05 (затримки
 * і розподіл причин) та розріз walk_in vs scheduled.
 */
#[CoversClass(BookingCounters::class)]
final class BookingCountersTest extends TestCase
{
    #[Test]
    public function countsStatusesTypesDelaysAndRejectionReasons(): void
    {
        $counters = BookingCounters::fromFacts([
            Fixtures::booking(bookingId: 'c1', status: BookingStatus::Completed, unloadedPalletsCount: 10, palletsCount: 10),
            Fixtures::booking(bookingId: 'c2', status: BookingStatus::Completed, unloadedPalletsCount: 6, palletsCount: 10, partialUnload: true),
            Fixtures::booking(bookingId: 'n1', status: BookingStatus::NoShow, palletsCount: 5),
            Fixtures::booking(bookingId: 'x1', status: BookingStatus::Cancelled, palletsCount: 5),
            Fixtures::booking(
                bookingId: 'r1',
                status: BookingStatus::Rejected,
                palletsCount: 8,
                rejectedReason: RejectionReason::WeightExceeded,
            ),
            Fixtures::booking(
                bookingId: 'w1',
                type: BookingType::WalkIn,
                status: BookingStatus::Completed,
                palletsCount: 2,
                unloadedPalletsCount: 2,
                delayed: true,
                delayReason: 'Затор на трасі',
            ),
            Fixtures::booking(
                bookingId: 'd2',
                status: BookingStatus::Completed,
                palletsCount: 1,
                delayed: true,
                delayReason: 'Затор на трасі',
            ),
            Fixtures::booking(bookingId: 'd3', status: BookingStatus::Completed, palletsCount: 1, delayed: true),
        ]);

        self::assertSame(8, $counters->total);
        self::assertSame(5, $counters->status(BookingStatus::Completed));
        self::assertSame(1, $counters->status(BookingStatus::NoShow));
        self::assertSame(1, $counters->status(BookingStatus::Cancelled));
        self::assertSame(1, $counters->status(BookingStatus::Rejected));
        self::assertSame(1, $counters->type(BookingType::WalkIn));
        self::assertSame(7, $counters->type(BookingType::Scheduled));
        self::assertSame(3, $counters->delayedCount);
        self::assertSame(2, $counters->byDelayReason['Затор на трасі']);
        self::assertSame(1, $counters->byDelayReason['unspecified'], 'Затримка без причини рахується окремо');
        self::assertSame(['weight_exceeded' => 1], $counters->byRejectionReason);
        self::assertSame(1, $counters->partialUnloadCount);
        self::assertSame(42, $counters->plannedPallets);
        self::assertSame(18, $counters->unloadedPallets);
    }

    #[Test]
    public function emptySelectionGivesZeroedCountersForEveryStatus(): void
    {
        $counters = BookingCounters::fromFacts([]);

        self::assertSame(0, $counters->total);
        self::assertSame(0, $counters->status(BookingStatus::Completed));
        self::assertSame([], $counters->byRejectionReason);
        self::assertSame([], $counters->byDelayReason);
    }
}
