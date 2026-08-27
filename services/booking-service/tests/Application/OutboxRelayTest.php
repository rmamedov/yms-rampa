<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Outbox\AnalyticsEventSink;
use App\Application\Outbox\OutboxRelay;
use App\Application\Outbox\SinkReport;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Exception\UpstreamUnavailableException;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryOutboxStore;
use App\Tests\Support\Scenario;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Релей outbox (DATA-16, KPI-05) — відсутня друга половина схеми
 * transactional outbox, через яку аналітика не мала жодних даних.
 */
#[CoversClass(OutboxRelay::class)]
#[CoversClass(SinkReport::class)]
final class OutboxRelayTest extends TestCase
{
    /**
     * Конверт має відповідати контракту analytics-service: eventId (ключ
     * ідемпотентності) — це _id запису outbox, а поле назви зветься `name`,
     * а не `eventType`.
     */
    public function testEnvelopeMatchesAnalyticsContract(): void
    {
        $outbox = new InMemoryOutboxStore();
        $outbox->append([$this->event(EventType::BookingCreated, '2026-08-27T06:10:00Z')]);
        $sink = new RecordingSink();

        $this->relay($outbox, $sink)->relay();

        self::assertCount(1, $sink->batches);
        $envelope = $sink->batches[0][0];

        self::assertSame(['eventId', 'name', 'occurredAt', 'payload'], array_keys($envelope));
        self::assertSame('ob-000001', $envelope['eventId']);
        self::assertSame('BookingCreated', $envelope['name']);
        self::assertSame('2026-08-27T06:10:00Z', $envelope['occurredAt']);
        self::assertSame('bk-1', $envelope['payload']['bookingId']);
    }

    /** Порядок доставки — за часом виникнення: BookingCreated не має обганяти. */
    public function testEventsAreDeliveredInOccurrenceOrder(): void
    {
        $outbox = new InMemoryOutboxStore();
        $outbox->append([
            $this->event(EventType::BookingCreated, '2026-08-27T06:00:00Z'),
            $this->event(EventType::BookingArrived, '2026-08-27T07:00:00Z'),
            $this->event(EventType::UnloadingStarted, '2026-08-27T07:10:00Z'),
        ]);
        $sink = new RecordingSink();

        $this->relay($outbox, $sink)->relay();

        self::assertSame(
            ['BookingCreated', 'BookingArrived', 'UnloadingStarted'],
            array_column($sink->batches[0], 'name'),
        );
    }

    public function testDeliveredRecordsLeaveTheQueue(): void
    {
        $outbox = new InMemoryOutboxStore();
        $outbox->append([
            $this->event(EventType::BookingCreated, '2026-08-27T06:00:00Z'),
            $this->event(EventType::BookingArrived, '2026-08-27T07:00:00Z'),
        ]);

        $report = $this->relay($outbox, new RecordingSink())->relay();

        self::assertSame(2, $report->delivered);
        self::assertTrue($report->queueDrained);
        self::assertSame([], $outbox->pending());
    }

    public function testEmptyQueueIsNotDeliveredAtAll(): void
    {
        $sink = new RecordingSink();

        $report = $this->relay(new InMemoryOutboxStore(), $sink)->relay();

        self::assertTrue($report->isEmpty());
        self::assertSame(0, $report->batches);
        self::assertSame([], $sink->batches);
    }

    /**
     * Головна гарантія at-least-once: сусід недоступний — НІЧОГО не
     * позначається опублікованим, тож наступний прогін повторить доставку.
     */
    public function testNothingIsMarkedPublishedWhenNeighbourIsUnavailable(): void
    {
        $outbox = new InMemoryOutboxStore();
        $outbox->append([$this->event(EventType::BookingCreated, '2026-08-27T06:00:00Z')]);

        $sink = new class implements AnalyticsEventSink {
            public function deliver(array $events): SinkReport
            {
                throw UpstreamUnavailableException::analyticsService('Connection refused');
            }
        };

        try {
            $this->relay($outbox, $sink)->relay();
            self::fail('Очікувався UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame('analytics-service', $error->service);
        }

        self::assertCount(1, $outbox->pending(), 'Подія має лишитися в черзі до наступного прогону.');
    }

    public function testQueueIsDrainedInSeveralBatches(): void
    {
        $outbox = new InMemoryOutboxStore();
        for ($i = 0; $i < 5; ++$i) {
            $outbox->append([$this->event(EventType::BookingArrived, \sprintf('2026-08-27T06:%02d:00Z', $i))]);
        }
        $sink = new RecordingSink();

        $report = $this->relay($outbox, $sink)->relay(batchSize: 2);

        self::assertSame(5, $report->delivered);
        self::assertSame(3, $report->batches);
        self::assertSame([2, 2, 1], array_map('count', $sink->batches));
        self::assertTrue($report->queueDrained);
    }

    /**
     * Стеля пакетів захищає таймер від нескінченного прогону: решта черги
     * лишається неопублікованою і поїде наступної хвилини.
     */
    public function testMaxBatchesStopsTheRunAndLeavesTheRest(): void
    {
        $outbox = new InMemoryOutboxStore();
        for ($i = 0; $i < 6; ++$i) {
            $outbox->append([$this->event(EventType::BookingArrived, \sprintf('2026-08-27T06:%02d:00Z', $i))]);
        }

        $report = $this->relay($outbox, new RecordingSink())->relay(batchSize: 2, maxBatches: 2);

        self::assertSame(4, $report->delivered);
        self::assertFalse($report->queueDrained);
        self::assertCount(2, $outbox->pending());
    }

    /**
     * Черга, що скінчилася РІВНО на останньому дозволеному пакеті, вичерпана —
     * попереджати про «решту наступного прогону» тут нема про що.
     */
    public function testQueueEmptiedOnTheLastAllowedBatchCountsAsDrained(): void
    {
        $outbox = new InMemoryOutboxStore();
        for ($i = 0; $i < 3; ++$i) {
            $outbox->append([$this->event(EventType::BookingArrived, \sprintf('2026-08-27T06:%02d:00Z', $i))]);
        }

        $report = $this->relay($outbox, new RecordingSink())->relay(batchSize: 2, maxBatches: 2);

        self::assertSame(3, $report->delivered);
        self::assertTrue($report->queueDrained);
        self::assertSame([], $outbox->pending());
    }

    /**
     * Сироти й відхилені події НЕ зупиняють чергу — інакше один непридатний
     * запис назавжди заблокував би доставку і аналітика знову спорожніла б.
     * Але вони обовʼязково потрапляють у звіт.
     */
    public function testProblemEventsAreReportedButDoNotBlockTheQueue(): void
    {
        $outbox = new InMemoryOutboxStore();
        $outbox->append([$this->event(EventType::BookingArrived, '2026-08-27T06:00:00Z')]);

        $sink = new class implements AnalyticsEventSink {
            public function deliver(array $events): SinkReport
            {
                return new SinkReport(
                    orphan: 1,
                    failed: [['eventId' => 'ob-000001', 'reason' => 'Подія без поля city.']],
                );
            }
        };

        $report = $this->relay($outbox, $sink)->relay();

        self::assertSame(1, $report->delivered);
        self::assertSame([], $outbox->pending());
        self::assertTrue($report->sink->hasProblems());
        self::assertSame(1, $report->sink->orphan);
        self::assertSame('Подія без поля city.', $report->sink->failed[0]['reason']);
    }

    /**
     * P-04 наскрізно: реальні події реального бронювання доходять до приймача
     * в конвертах, які analytics-service вміє прочитати. `city` тут особливо
     * важливий — без нього сусід не може створити факт бронювання взагалі.
     */
    public function testRealBookingProducesEnvelopesAnalyticsCanRead(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->create(
            $scenario->supplier(),
            $scenario->request(),
            $scenario->now(),
        );
        $sink = new RecordingSink();

        (new OutboxRelay($scenario->outbox, $sink, $scenario->clock))->relay();

        $created = null;
        foreach ($sink->batches[0] as $envelope) {
            if ('BookingCreated' === $envelope['name']) {
                $created = $envelope;
            }
        }

        self::assertNotNull($created, 'Подія BookingCreated має потрапити в пакет.');
        self::assertSame($booking->id, $created['payload']['bookingId']);

        // Обовʼязкові поля App\Domain\Projection\EventProjector::createFact сусіда.
        foreach (['bookingId', 'storeId', 'city', 'supplierId', 'rampId', 'slotStart', 'slotEnd', 'type', 'palletsCount'] as $field) {
            self::assertArrayHasKey($field, $created['payload'], \sprintf('Поле «%s» потрібне аналітиці.', $field));
        }

        self::assertSame('Київ', $created['payload']['city']);
    }

    /**
     * Сумісність зі старими записами: подія BookingCreated, записана до появи
     * поля `city`, добирає місто зі снапшота філії того самого бронювання.
     * Без цього наявні на стенді бронювання ніколи не потрапили б у KPI.
     */
    public function testLegacyCreatedEventGetsCityFromBookingSnapshot(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();
        $scenario->outbox->clear();

        // Подія «старого» формату: точно такий payload писався до виправлення.
        $scenario->outbox->append([DomainEvent::forBooking(
            EventType::BookingCreated,
            $booking->id,
            ['bookingId' => $booking->id, 'storeId' => Scenario::STORE_ID, 'type' => 'scheduled'],
            $scenario->now(),
        )]);

        $sink = new RecordingSink();
        (new OutboxRelay($scenario->outbox, $sink, $scenario->clock, $scenario->bookings))->relay();

        self::assertSame('Київ', $sink->batches[0][0]['payload']['city']);
    }

    /** Бронювання зникло — подію не вигадуємо, сусід поверне її в `failed`. */
    public function testLegacyEventOfMissingBookingIsLeftAsIs(): void
    {
        $scenario = new Scenario();
        $scenario->outbox->append([DomainEvent::forBooking(
            EventType::BookingCreated,
            'bk-зниклий',
            ['bookingId' => 'bk-зниклий', 'storeId' => Scenario::STORE_ID],
            $scenario->now(),
        )]);

        $sink = new RecordingSink();
        (new OutboxRelay($scenario->outbox, $sink, $scenario->clock, $scenario->bookings))->relay();

        self::assertArrayNotHasKey('city', $sink->batches[0][0]['payload']);
    }

    /** Подій, які не є BookingCreated, добір не стосується взагалі. */
    public function testOtherEventsAreNotEnriched(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();
        $scenario->outbox->clear();
        $scenario->outbox->append([DomainEvent::forBooking(
            EventType::BookingArrived,
            $booking->id,
            ['bookingId' => $booking->id],
            $scenario->now(),
        )]);

        $sink = new RecordingSink();
        (new OutboxRelay($scenario->outbox, $sink, $scenario->clock, $scenario->bookings))->relay();

        self::assertSame(['bookingId' => $booking->id], $sink->batches[0][0]['payload']);
    }

    private function relay(InMemoryOutboxStore $outbox, AnalyticsEventSink $sink): OutboxRelay
    {
        return new OutboxRelay($outbox, $sink, new FrozenClock('2026-08-27T08:00:00Z'));
    }

    private function event(EventType $type, string $occurredAt): DomainEvent
    {
        return DomainEvent::forBooking(
            $type,
            'bk-1',
            ['bookingId' => 'bk-1', 'storeId' => Scenario::STORE_ID, 'city' => 'Київ'],
            new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')),
        );
    }
}

/**
 * Приймач, який лише запамʼятовує пакети: транспорт у цих тестах не бере
 * участі — його перевіряє HttpAnalyticsEventSinkTest.
 */
final class RecordingSink implements AnalyticsEventSink
{
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    public function deliver(array $events): SinkReport
    {
        $this->batches[] = $events;

        return new SinkReport(applied: \count($events));
    }
}
