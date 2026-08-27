<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;
use App\Infrastructure\Mongo\BookingFactDocumentMapper;
use App\Infrastructure\Mongo\SlotFactDocumentMapper;
use App\Domain\Slot\SlotState;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Мапери read-моделей у документи MongoDB. Тести навмисно не потребують
 * ext-mongodb: перетворення дат у BSON виконує окремий BsonCodec.
 */
#[CoversClass(BookingFactDocumentMapper::class)]
#[CoversClass(SlotFactDocumentMapper::class)]
final class BookingFactDocumentMapperTest extends TestCase
{
    #[Test]
    public function bookingFactSurvivesRoundTripThroughDocument(): void
    {
        $mapper = new BookingFactDocumentMapper();

        $fact = Fixtures::booking(
            bookingId: 'b1',
            type: BookingType::WalkIn,
            status: BookingStatus::Rejected,
            arrivedAt: '2026-03-16 07:50:00',
            unloadingStartedAt: '2026-03-16 08:00:00',
            completedAt: '2026-03-16 08:25:00',
            unloadedPalletsCount: 7,
            partialUnload: true,
            delayed: true,
            delayReason: 'Затор на трасі',
            rejectedReason: RejectionReason::DocumentsMissing,
            processedEventIds: ['evt-1', 'evt-2'],
        );

        $restored = $mapper->fromDocument($mapper->toDocument($fact));

        self::assertSame('b1', $restored->bookingId);
        self::assertSame(BookingType::WalkIn, $restored->type);
        self::assertSame(BookingStatus::Rejected, $restored->status());
        self::assertEquals($fact->arrivedAt(), $restored->arrivedAt());
        self::assertEquals($fact->completedAt(), $restored->completedAt());
        self::assertSame(7, $restored->unloadedPalletsCount());
        self::assertTrue($restored->isPartialUnload());
        self::assertTrue($restored->isDelayed());
        self::assertSame('Затор на трасі', $restored->delayReason());
        self::assertSame(RejectionReason::DocumentsMissing, $restored->rejectedReason());
        self::assertSame(['evt-1', 'evt-2'], $restored->processedEventIds());
    }

    /**
     * Ідемпотентність переживає перезавантаження з БД: перелік застосованих
     * eventId зберігається в документі.
     */
    #[Test]
    public function documentKeepsProcessedEventIdsForIdempotency(): void
    {
        $mapper = new BookingFactDocumentMapper();
        $document = $mapper->toDocument(Fixtures::booking(processedEventIds: ['evt-created', 'evt-arrived']));

        self::assertSame(['evt-created', 'evt-arrived'], $document['processedEventIds']);
        self::assertTrue($mapper->fromDocument($document)->hasProcessed('evt-arrived'));
    }

    #[Test]
    public function documentIsReadableWhenDatesComeBackAsIsoStrings(): void
    {
        $mapper = new BookingFactDocumentMapper();
        $document = $mapper->toDocument(Fixtures::booking(bookingId: 'b1', arrivedAt: '2026-03-16 07:50:00'));

        $document['slotStart'] = '2026-03-16T08:00:00+00:00';
        $document['arrivedAt'] = '2026-03-16T07:50:00+00:00';

        $restored = $mapper->fromDocument($document);

        self::assertSame('2026-03-16 08:00:00', $restored->slotStart->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 07:50:00', $restored->arrivedAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function slotFactSurvivesRoundTripThroughDocument(): void
    {
        $mapper = new SlotFactDocumentMapper();
        $slot = Fixtures::slot('s1', SlotState::Reserved, '2026-03-16 08:00:00', '2026-03-16 08:30:00');

        $document = $mapper->toDocument($slot);
        $restored = $mapper->fromDocument($document);

        self::assertSame(30.0, $document['minutes']);
        self::assertSame(SlotState::Reserved, $restored->state);
        self::assertEquals($slot->start, $restored->start);
        self::assertSame('ramp-1', $restored->rampId);
    }
}
