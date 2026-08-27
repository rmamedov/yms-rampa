<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Domain\Booking\Booking;
use App\Domain\Booking\PartialUnload;
use App\Domain\Booking\PartialUnloadReason;
use App\Domain\Booking\RejectionReason;
use App\Domain\Event\DomainEvent;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Контракт payload доменних подій із боку ВИДАВЦЯ.
 *
 * Це не абстрактні перевірки формату: кожне твердження тут відповідає полю,
 * якого analytics-service вимагає в App\Domain\Projection\EventProjector.
 * Живий прогін релея на стенді показав, чого це коштує, коли контракт
 * розходиться: 192 події BookingReassigned відхилено через відсутній rampId,
 * розріз причин відмов лишався порожній, а лічильник часткових розвантажень —
 * нульовий.
 */
#[CoversClass(Booking::class)]
final class AnalyticsEventContractTest extends TestCase
{
    /**
     * Спільні поля є в КОЖНІЙ події бронювання. Саме через те, що rampId
     * колись не входив у цей набір, події переведення його не несли.
     */
    public function testEveryBookingEventCarriesSharedFields(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();
        $scenario->lifecycle->markArrived($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 09:55'));
        $scenario->lifecycle->startUnloading($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 10:00'));

        $events = array_map(
            static fn ($record): DomainEvent => $record->event,
            $scenario->outbox->all(),
        );

        self::assertNotEmpty($events);

        foreach ($events as $event) {
            if ('booking' !== $event->aggregateType) {
                continue;
            }

            foreach (['bookingId', 'storeId', 'city', 'rampId', 'supplierId', 'status'] as $field) {
                self::assertArrayHasKey($field, $event->payload, \sprintf(
                    'Подія %s мусить нести спільне поле «%s».',
                    $event->type->value,
                    $field,
                ));
            }
        }
    }

    /** EDIT-06: переведення на іншу рампу. */
    public function testRampMoveCarriesNewRampId(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book(rampId: 'r1');

        $scenario->lifecycle->moveToRamp(
            $scenario->storeStaff(),
            $booking->id,
            'r2',
            Scenario::kyiv('2026-08-28 09:00'),
        );

        $event = $this->lastOfType($scenario, 'BookingReassigned');

        self::assertSame('r2', $event->payload['rampId']);
    }

    /**
     * Переведення водія/авто рампи не змінює — але подія однаково мусить її
     * нести: без rampId аналітика відкидає подію цілком.
     */
    public function testDriverAndVehicleReassignmentCarriesRampId(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book(rampId: 'r2');

        $scenario->lifecycle->reassign(
            actor: $scenario->storeStaff(),
            bookingId: $booking->id,
            now: Scenario::kyiv('2026-08-28 09:00'),
            vehicle: Scenario::vehicle('BB5678CC', 6.0),
        );

        $event = $this->lastOfType($scenario, 'BookingReassigned');

        self::assertSame('driver_or_vehicle', $event->payload['reason']);
        self::assertSame('r2', $event->payload['rampId']);
    }

    /**
     * Найчастіший шлях на стенді: призначення водія в маршрутному листі.
     * Подія тут збирається вручну, повз Booking::event(), тому rampId у ній
     * доводиться тримати окремо — і саме тут його бракувало.
     */
    public function testDriverAssignmentFromRouteSheetCarriesRampId(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book(rampId: 'r1');
        $scenario->outbox->clear();

        $scenario->routeSheets->assignDriverToBooking(
            $scenario->supplier(),
            $booking->id,
            'du-7',
            Scenario::kyiv('2026-08-28 09:00'),
        );

        $event = $this->lastOfType($scenario, 'BookingReassigned');

        self::assertSame('driver_assignment', $event->payload['reason']);
        self::assertSame('r1', $event->payload['rampId']);
        self::assertSame($booking->id, $event->payload['bookingId']);
    }

    /**
     * ST-07: причина відмови має лежати ВЕРХНІМ рівнем — саме звідти її
     * читають і аналітика (розріз причин відмов), і notification-service.
     */
    public function testRejectionCarriesReasonAtTopLevel(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();
        $scenario->lifecycle->markArrived($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 09:55'));

        $scenario->lifecycle->reject(
            $scenario->storeStaff(),
            $booking->id,
            RejectionReason::MissingDocuments,
            Scenario::kyiv('2026-08-28 10:05'),
        );

        $event = $this->lastOfType($scenario, 'BookingRejected');

        self::assertSame(RejectionReason::MissingDocuments->value, $event->payload['reason']);
        self::assertNull($event->payload['comment']);
        // rejectedAt — саме дата, а не вкладений обʼєкт.
        self::assertIsString($event->payload['rejectedAt']);
        self::assertSame('2026-08-28T07:05:00Z', $event->payload['rejectedAt']);
        // Подробиці лишаються поруч, окремим полем.
        self::assertSame(RejectionReason::MissingDocuments->value, $event->payload['rejection']['reason']);
    }

    public function testRejectionCommentIsCarriedToo(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();
        $scenario->lifecycle->markArrived($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 09:55'));

        $scenario->lifecycle->reject(
            $scenario->storeStaff(),
            $booking->id,
            RejectionReason::Other,
            Scenario::kyiv('2026-08-28 10:05'),
            'водій без документів',
        );

        $event = $this->lastOfType($scenario, 'BookingRejected');

        self::assertSame(RejectionReason::Other->value, $event->payload['reason']);
        self::assertSame('водій без документів', $event->payload['comment']);
    }

    /** ANL-04: `partialUnload` — прапорець, який читають як булеве. */
    public function testPartialUnloadIsABooleanFlag(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book(palletsCount: 8);
        $scenario->lifecycle->markArrived($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 09:55'));
        $scenario->lifecycle->startUnloading($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 10:00'));

        $scenario->lifecycle->complete(
            $scenario->storeStaff(),
            $booking->id,
            Scenario::kyiv('2026-08-28 10:25'),
            5,
            new PartialUnload(PartialUnloadReason::Damaged),
        );

        $event = $this->lastOfType($scenario, 'UnloadingCompleted');

        self::assertTrue($event->payload['partialUnload']);
        self::assertSame('бій/брак', $event->payload['partialUnloadDetails']['reason']);
    }

    /** Повне розвантаження — прапорець false, а не null і не обʼєкт. */
    public function testFullUnloadReportsFalseFlag(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book(palletsCount: 8);
        $scenario->lifecycle->markArrived($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 09:55'));
        $scenario->lifecycle->startUnloading($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 10:00'));
        $scenario->lifecycle->complete($scenario->storeStaff(), $booking->id, Scenario::kyiv('2026-08-28 10:25'));

        $event = $this->lastOfType($scenario, 'UnloadingCompleted');

        self::assertFalse($event->payload['partialUnload']);
        self::assertNull($event->payload['partialUnloadDetails']);
    }

    private function lastOfType(Scenario $scenario, string $type): DomainEvent
    {
        $events = $scenario->outbox->eventsOfType($type);

        self::assertNotEmpty($events, \sprintf('Подія %s не потрапила в outbox.', $type));

        return $events[array_key_last($events)];
    }
}
