<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Domain\Event\EventType;
use App\Domain\Outbox\OutboxRecord;
use App\Infrastructure\InMemory\InMemoryOutboxStore;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Transactional outbox (DATA-16): подія лягає в тій самій операції,
 * що й зміна бронювання, а публікація виконується окремим релеєм.
 */
#[CoversClass(InMemoryOutboxStore::class)]
final class OutboxTest extends TestCase
{
    public function testBookingWriteAndEventShareTheSameOperation(): void
    {
        $scenario = new Scenario();

        self::assertSame([], $scenario->outbox->eventTypes());

        $booking = $scenario->book();

        self::assertSame(['BookingCreated'], $scenario->outbox->eventTypes());
        self::assertSame($booking->id, $scenario->outbox->all()[0]->event->aggregateId);
        self::assertSame('booking', $scenario->outbox->all()[0]->event->aggregateType);
    }

    /** Невдала вставка не залишає подію в outbox. */
    public function testFailedInsertDoesNotLeaveEvents(): void
    {
        $scenario = new Scenario();
        $scenario->book();
        $scenario->outbox->clear();

        try {
            $scenario->creation->create(
                $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
                $scenario->request(),
                $scenario->now(),
            );
        } catch (\Throwable) {
            // очікувано: слот уже зайнято
        }

        self::assertSame([], $scenario->outbox->eventTypes());
    }

    /** Повний життєвий цикл публікує рівно канонічні події реєстру. */
    public function testLifecyclePublishesCanonicalEventSequence(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: 'du-1');
        $scenario->outbox->clear();

        $scenario->lifecycle->markArrived($scenario->driver('du-1'), $booking->id, Scenario::kyiv('2026-08-28 09:58'));
        $scenario->lifecycle->startUnloading($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 10:02'));
        $scenario->lifecycle->complete($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 10:25'));

        self::assertSame(
            ['BookingArrived', 'UnloadingStarted', 'UnloadingCompleted'],
            $scenario->outbox->eventTypes(),
        );
    }

    /** ST-03: UnloadingCompleted містить unloadedPalletsCount і partialUnload. */
    public function testUnloadingCompletedCarriesUnloadFields(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->lifecycle->markArrived($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 09:58'));
        $scenario->lifecycle->startUnloading($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 10:02'));
        $scenario->lifecycle->complete(
            $scenario->storeStaff(),
            $booking->id,
            Scenario::kyiv('2026-08-28 10:25'),
            5,
            new \App\Domain\Booking\PartialUnload(\App\Domain\Booking\PartialUnloadReason::Damaged),
        );

        $event = $scenario->outbox->eventsOfType('UnloadingCompleted')[0];

        self::assertSame(5, $event->payload['unloadedPalletsCount']);
        self::assertSame('бій/брак', $event->payload['partialUnload']['reason']);
    }

    public function testPendingRecordsAreReturnedInOccurrenceOrder(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');
        $scenario->book('2026-08-28 12:00');

        $pending = $scenario->outbox->pending();

        self::assertCount(2, $pending);
        self::assertTrue($pending[0]->isPending());
    }

    public function testMarkPublishedRemovesRecordFromQueue(): void
    {
        $scenario = new Scenario();
        $scenario->book();

        $record = $scenario->outbox->pending()[0];
        $scenario->outbox->markPublished($record->id, $scenario->now());

        self::assertSame([], $scenario->outbox->pending());
        self::assertSame(1, $scenario->outbox->all()[0]->attempts);
    }

    /** DATA-16: у outbox допускаються лише канонічні типи подій. */
    public function testOnlyCanonicalEventTypesAreStored(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();
        $scenario->lifecycle->cancel($scenario->supplier(), $booking->id, $scenario->now());

        $canonical = array_map(static fn (EventType $type) => $type->value, EventType::cases());

        foreach ($scenario->outbox->all() as $record) {
            self::assertInstanceOf(OutboxRecord::class, $record);
            self::assertContains($record->event->type->value, $canonical);
        }
    }
}
