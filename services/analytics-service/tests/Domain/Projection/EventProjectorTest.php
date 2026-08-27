<?php

declare(strict_types=1);

namespace App\Tests\Domain\Projection;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;
use App\Domain\Exception\MalformedEventException;
use App\Domain\Projection\DomainEvent;
use App\Domain\Projection\DomainEventName;
use App\Domain\Projection\EventProjector;
use App\Domain\Projection\ProjectionOutcome;
use App\Infrastructure\InMemory\InMemoryBookingFactRepository;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Проєктор канонічних доменних подій у read-модель BookingFact (KPI-05),
 * з обовʼязковою перевіркою ідемпотентності повторної доставки.
 */
#[CoversClass(EventProjector::class)]
final class EventProjectorTest extends TestCase
{
    private InMemoryBookingFactRepository $repository;
    private EventProjector $projector;

    protected function setUp(): void
    {
        $this->repository = new InMemoryBookingFactRepository();
        $this->projector = new EventProjector($this->repository);
    }

    #[Test]
    public function buildsFullBookingFactFromLifecycleEvents(): void
    {
        $this->projector->projectMany($this->lifecycleEvents());

        $fact = $this->repository->findByBookingId('b1');

        self::assertNotNull($fact);
        self::assertSame(BookingStatus::Completed, $fact->status());
        self::assertSame('store-1', $fact->storeId);
        self::assertSame('Київ', $fact->city);
        self::assertSame(BookingType::Scheduled, $fact->type);
        self::assertSame(12, $fact->palletsCount);
        self::assertSame(9, $fact->unloadedPalletsCount());
        self::assertTrue($fact->isPartialUnload());
        self::assertSame('2026-03-16 07:50:00', $fact->arrivedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 08:00:00', $fact->unloadingStartedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 08:25:00', $fact->completedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(10.0, $fact->waitingMinutes());
        self::assertSame(25.0, $fact->unloadingMinutes());
    }

    /**
     * Ідемпотентність: повторна доставка тих самих подій (той самий eventId)
     * не дублює і не псує факт.
     */
    #[Test]
    public function redeliveryOfSameEventsDoesNotDuplicateOrCorruptFact(): void
    {
        $this->projector->projectMany($this->lifecycleEvents());
        $before = $this->repository->findByBookingId('b1');
        self::assertNotNull($before);

        $snapshot = [
            'status' => $before->status(),
            'arrivedAt' => $before->arrivedAt(),
            'unloadingStartedAt' => $before->unloadingStartedAt(),
            'completedAt' => $before->completedAt(),
            'unloadedPallets' => $before->unloadedPalletsCount(),
            'events' => $before->processedEventIds(),
        ];

        $results = $this->projector->projectMany($this->lifecycleEvents());

        foreach ($results as $result) {
            self::assertSame(ProjectionOutcome::Duplicate, $result->outcome);
        }

        $after = $this->repository->findByBookingId('b1');
        self::assertNotNull($after);
        self::assertSame(1, $this->repository->countAll());
        self::assertSame($snapshot['status'], $after->status());
        self::assertEquals($snapshot['arrivedAt'], $after->arrivedAt());
        self::assertEquals($snapshot['unloadingStartedAt'], $after->unloadingStartedAt());
        self::assertEquals($snapshot['completedAt'], $after->completedAt());
        self::assertSame($snapshot['unloadedPallets'], $after->unloadedPalletsCount());
        self::assertSame($snapshot['events'], $after->processedEventIds());
        self::assertCount(4, $after->processedEventIds());
    }

    /**
     * Повторна доставка тієї самої події з іншим значенням поля (брокер міг
     * переслати спотворену копію) не перезаписує вже зафіксовану мітку часу.
     */
    #[Test]
    public function redeliveredEventWithDifferentPayloadDoesNotOverwriteTimestamps(): void
    {
        $this->projector->project($this->created());
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingArrived,
            ['bookingId' => 'b1', 'arrivedAt' => '2026-03-16T07:50:00+00:00'],
            eventId: 'evt-arrived',
        ));

        $result = $this->projector->project(Fixtures::event(
            DomainEventName::BookingArrived,
            ['bookingId' => 'b1', 'arrivedAt' => '2026-03-16T09:15:00+00:00'],
            eventId: 'evt-arrived',
        ));

        self::assertSame(ProjectionOutcome::Duplicate, $result->outcome);
        self::assertSame(
            '2026-03-16 07:50:00',
            $this->repository->findByBookingId('b1')?->arrivedAt()?->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Порушений порядок доставки: спершу UnloadingCompleted, потім BookingArrived.
     * Статус не «відкочується» назад, але мітка arrivedAt дозаписується.
     */
    #[Test]
    public function outOfOrderDeliveryDoesNotRollBackStatus(): void
    {
        $this->projector->project($this->created());
        $this->projector->project(Fixtures::event(
            DomainEventName::UnloadingCompleted,
            ['bookingId' => 'b1', 'completedAt' => '2026-03-16T08:25:00+00:00', 'unloadedPalletsCount' => 12],
            eventId: 'evt-completed',
        ));
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingArrived,
            ['bookingId' => 'b1', 'arrivedAt' => '2026-03-16T07:50:00+00:00'],
            eventId: 'evt-arrived',
        ));

        $fact = $this->repository->findByBookingId('b1');

        self::assertNotNull($fact);
        self::assertSame(BookingStatus::Completed, $fact->status());
        self::assertSame('2026-03-16 07:50:00', $fact->arrivedAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function eventBeforeBookingCreatedIsReportedAsOrphan(): void
    {
        $result = $this->projector->project(Fixtures::event(
            DomainEventName::BookingArrived,
            ['bookingId' => 'unknown'],
            eventId: 'evt-orphan',
        ));

        self::assertSame(ProjectionOutcome::Orphan, $result->outcome);
        self::assertSame(0, $this->repository->countAll());
    }

    #[Test]
    public function eventsUnrelatedToBookingsAreIgnored(): void
    {
        $result = $this->projector->project(Fixtures::event(
            DomainEventName::DriverCreated,
            ['driverId' => 'd1'],
            eventId: 'evt-driver',
        ));

        self::assertSame(ProjectionOutcome::Ignored, $result->outcome);
        self::assertSame(0, $this->repository->countAll());
    }

    #[Test]
    public function walkInBookingIsCreatedAlreadyArrived(): void
    {
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingCreated,
            Fixtures::bookingCreatedPayload([
                'bookingId' => 'w1',
                'type' => 'walk_in',
                'arrivedAt' => '2026-03-16T08:05:00+00:00',
            ]),
            eventId: 'evt-walkin',
        ));

        $fact = $this->repository->findByBookingId('w1');

        self::assertNotNull($fact);
        self::assertSame(BookingType::WalkIn, $fact->type);
        self::assertSame(BookingStatus::Arrived, $fact->status());
        self::assertSame('2026-03-16 08:05:00', $fact->arrivedAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function rejectionStoresReasonFromDictionaryAndUnknownCodeBecomesOther(): void
    {
        $this->projector->project($this->created());
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingRejected,
            ['bookingId' => 'b1', 'reason' => 'weight_exceeded'],
            eventId: 'evt-reject',
        ));

        self::assertSame(
            RejectionReason::WeightExceeded,
            $this->repository->findByBookingId('b1')?->rejectedReason(),
        );

        $this->projector->project(Fixtures::event(
            DomainEventName::BookingCreated,
            Fixtures::bookingCreatedPayload(['bookingId' => 'b2']),
            eventId: 'evt-created-2',
        ));
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingRejected,
            ['bookingId' => 'b2', 'reason' => 'щось нове з довідника'],
            eventId: 'evt-reject-2',
        ));

        $fact = $this->repository->findByBookingId('b2');
        self::assertSame(RejectionReason::Other, $fact?->rejectedReason());
        self::assertSame(BookingStatus::Rejected, $fact?->status());
    }

    #[Test]
    public function delayIsAnAttributeOverCurrentStatusAndCanBeCleared(): void
    {
        $this->projector->project($this->created());
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingDelaySet,
            ['bookingId' => 'b1', 'delayed' => true, 'reason' => 'Затор на трасі', 'eta' => '2026-03-16T09:30:00+00:00'],
            eventId: 'evt-delay',
        ));

        $delayed = $this->repository->findByBookingId('b1');
        self::assertTrue($delayed?->isDelayed());
        self::assertSame('Затор на трасі', $delayed?->delayReason());
        self::assertSame(BookingStatus::Booked, $delayed?->status(), 'Затримка не є статусом');
        self::assertSame('2026-03-16 09:30:00', $delayed?->delayEta()?->format('Y-m-d H:i:s'));

        $this->projector->project(Fixtures::event(
            DomainEventName::BookingDelaySet,
            ['bookingId' => 'b1', 'delayed' => false],
            eventId: 'evt-delay-off',
        ));

        $cleared = $this->repository->findByBookingId('b1');
        self::assertFalse($cleared?->isDelayed());
        self::assertNull($cleared?->delayReason());
    }

    #[Test]
    public function cancellationIsTerminalAndLaterArrivalDoesNotChangeStatus(): void
    {
        $this->projector->project($this->created());
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingCancelled,
            ['bookingId' => 'b1'],
            eventId: 'evt-cancel',
            occurredAt: '2026-03-16 07:00:00',
        ));
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingArrived,
            ['bookingId' => 'b1'],
            eventId: 'evt-late-arrival',
            occurredAt: '2026-03-16 07:50:00',
        ));

        self::assertSame(BookingStatus::Cancelled, $this->repository->findByBookingId('b1')?->status());
    }

    #[Test]
    public function reassignmentChangesRamp(): void
    {
        $this->projector->project($this->created());
        $this->projector->project(Fixtures::event(
            DomainEventName::BookingReassigned,
            ['bookingId' => 'b1', 'rampId' => 'ramp-7'],
            eventId: 'evt-reassign',
        ));

        self::assertSame('ramp-7', $this->repository->findByBookingId('b1')?->rampId());
    }

    #[Test]
    public function noShowIsProjectedForKpi04(): void
    {
        $this->projector->project($this->created());
        $result = $this->projector->project(Fixtures::event(
            DomainEventName::BookingNoShow,
            ['bookingId' => 'b1'],
            eventId: 'evt-noshow',
            occurredAt: '2026-03-16 09:00:00',
        ));

        self::assertSame(ProjectionOutcome::Applied, $result->outcome);
        $fact = $this->repository->findByBookingId('b1');
        self::assertSame(BookingStatus::NoShow, $fact?->status());
        self::assertSame('2026-03-16 09:00:00', $fact?->noShowAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function eventWithoutBookingIdIsRejected(): void
    {
        $this->expectException(MalformedEventException::class);

        $this->projector->project(Fixtures::event(DomainEventName::BookingArrived, [], eventId: 'evt-bad'));
    }

    #[Test]
    public function bookingCreatedWithUnknownTypeIsRejected(): void
    {
        $this->expectException(MalformedEventException::class);
        $this->expectExceptionMessage('невідомий тип бронювання');

        $this->projector->project(Fixtures::event(
            DomainEventName::BookingCreated,
            Fixtures::bookingCreatedPayload(['type' => 'express']),
            eventId: 'evt-bad-type',
        ));
    }

    #[Test]
    public function unknownEventNameIsRejectedOnDeserialization(): void
    {
        $this->expectException(MalformedEventException::class);

        DomainEvent::fromArray([
            'eventId' => 'evt-x',
            'name' => 'BookingTeleported',
            'occurredAt' => '2026-03-16T08:00:00+00:00',
            'payload' => [],
        ]);
    }

    private function created(): DomainEvent
    {
        return Fixtures::event(
            DomainEventName::BookingCreated,
            Fixtures::bookingCreatedPayload(),
            eventId: 'evt-created',
            occurredAt: '2026-03-15 12:00:00',
        );
    }

    /**
     * @return list<DomainEvent>
     */
    private function lifecycleEvents(): array
    {
        return [
            $this->created(),
            Fixtures::event(
                DomainEventName::BookingArrived,
                ['bookingId' => 'b1', 'arrivedAt' => '2026-03-16T07:50:00+00:00'],
                eventId: 'evt-arrived',
            ),
            Fixtures::event(
                DomainEventName::UnloadingStarted,
                ['bookingId' => 'b1', 'startedAt' => '2026-03-16T08:00:00+00:00'],
                eventId: 'evt-unloading',
            ),
            Fixtures::event(
                DomainEventName::UnloadingCompleted,
                [
                    'bookingId' => 'b1',
                    'completedAt' => '2026-03-16T08:25:00+00:00',
                    'unloadedPalletsCount' => 9,
                    'partialUnload' => true,
                ],
                eventId: 'evt-completed',
            ),
        ];
    }
}
