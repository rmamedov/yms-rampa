<?php

declare(strict_types=1);

namespace App\Tests\Controller\Internal;

use App\Controller\Admin\AnalyticsController;
use App\Controller\Internal\InternalEventIngestController;
use App\Domain\Analytics\AnalyticsDashboard;
use App\Domain\Booking\RejectionReason;
use App\Domain\Exception\MalformedEventException;
use App\Domain\Fact\BookingFact;
use App\Domain\Projection\EventProjector;
use App\Infrastructure\Http\AnalyticsQueryFactory;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryBookingFactRepository;
use App\Infrastructure\InMemory\InMemorySlotFactRepository;
use App\Infrastructure\Messaging\DomainEventConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Службовий маршрут POST /internal/v1/analytics/events — те, чим релей outbox
 * booking-service наповнює read-моделі.
 *
 * До появи цього маршруту потік подій на стенді не існував узагалі, тому
 * розділ аналітики завжди відповідав «Немає даних за обраний період».
 */
#[CoversClass(InternalEventIngestController::class)]
#[CoversClass(DomainEventConsumer::class)]
final class InternalEventIngestControllerTest extends TestCase
{
    private InMemoryBookingFactRepository $facts;
    private InternalEventIngestController $controller;

    protected function setUp(): void
    {
        $this->facts = new InMemoryBookingFactRepository();
        $this->controller = new InternalEventIngestController(
            new DomainEventConsumer(new EventProjector($this->facts)),
        );
    }

    #[Test]
    public function ingestsBatchAndReportsOutcomes(): void
    {
        $payload = $this->ingest([
            $this->created('ob-1', 'b1'),
            $this->arrived('ob-2', 'b1'),
            // BranchSynced до read-моделі бронювань не належить — ігнорується.
            ['eventId' => 'ob-3', 'name' => 'BranchSynced', 'occurredAt' => '2026-03-16T06:00:00Z', 'payload' => []],
        ]);

        self::assertSame(3, $payload['received']);
        self::assertSame(2, $payload['applied']);
        self::assertSame(1, $payload['ignored']);
        self::assertSame(0, $payload['orphan']);
        self::assertSame(0, $payload['rejected']);
        self::assertSame(1, $this->facts->countAll());

        // Присуд за КОЖНОЮ подією: без нього відправник не знає, що можна
        // прибрати зі своєї черги, а що ні.
        self::assertSame([0, 1, 2], array_column($payload['results'], 'index'));
        self::assertSame(['ob-1', 'ob-2', 'ob-3'], array_column($payload['results'], 'eventId'));
        self::assertSame(['applied', 'applied', 'ignored'], array_column($payload['results'], 'outcome'));
    }

    /**
     * Доставка at-least-once: релей може повторити той самий пакет після
     * обриву. Повтор не має ані дублювати факти, ані ламатися.
     */
    #[Test]
    public function repeatedBatchIsRecognisedAsDuplicate(): void
    {
        $batch = [$this->created('ob-1', 'b1'), $this->arrived('ob-2', 'b1')];

        $this->ingest($batch);
        $second = $this->ingest($batch);

        self::assertSame(0, $second['applied']);
        self::assertSame(2, $second['duplicate']);
        self::assertSame(1, $this->facts->countAll());
    }

    /**
     * Одна непридатна подія НЕ валить пакет: інакше релей вічно повторював би
     * той самий пакет і черга застрягла б назавжди.
     */
    #[Test]
    public function malformedEventDoesNotRejectTheWholeBatch(): void
    {
        $payload = $this->ingest([
            $this->created('ob-1', 'b1'),
            // BookingCreated без обовʼязкового palletsCount — факт неповний.
            [
                'eventId' => 'ob-2',
                'name' => 'BookingCreated',
                'occurredAt' => '2026-03-16T06:00:00Z',
                'payload' => [
                    'bookingId' => 'b2',
                    'storeId' => 'store-1',
                    'city' => 'Київ',
                    'supplierId' => 'sup-1',
                    'rampId' => 'ramp-1',
                    'slotStart' => '2026-03-16T08:00:00Z',
                    'slotEnd' => '2026-03-16T08:30:00Z',
                    'type' => 'scheduled',
                ],
            ],
            $this->arrived('ob-3', 'b1'),
        ]);

        self::assertSame(3, $payload['received']);
        self::assertSame(2, $payload['applied']);
        self::assertSame(1, $payload['rejected']);

        $rejected = $payload['results'][1];
        self::assertSame('rejected', $rejected['outcome']);
        self::assertSame('ob-2', $rejected['eventId']);
        self::assertStringContainsString('palletsCount', $rejected['reason']);
    }

    /** Подія без BookingCreated — сирота, і сусід має це чесно повідомити. */
    #[Test]
    public function eventWithoutCreatedFactIsReportedAsOrphan(): void
    {
        $payload = $this->ingest([$this->arrived('ob-1', 'b-unknown')]);

        self::assertSame(1, $payload['orphan']);
        self::assertSame('orphan', $payload['results'][0]['outcome']);
        self::assertNotNull($payload['results'][0]['reason']);
        self::assertSame(0, $this->facts->countAll());
    }

    #[Test]
    public function nonObjectElementIsReportedWithoutBreakingTheBatch(): void
    {
        $payload = $this->ingest([$this->created('ob-1', 'b1'), 'не подія']);

        self::assertSame(1, $payload['applied']);
        self::assertSame(1, $payload['rejected']);
        self::assertSame('rejected', $payload['results'][1]['outcome']);
        self::assertNull($payload['results'][1]['eventId']);
    }

    /**
     * Присуд повертається на КОЖНУ надіслану подію і в тому самому порядку —
     * саме за позицією відправник звіряє їх зі своєю чергою.
     */
    #[Test]
    public function everySentEventGetsItsOwnVerdict(): void
    {
        $events = [
            $this->created('ob-1', 'b1'),
            $this->arrived('ob-2', 'b1'),
            $this->arrived('ob-2', 'b1'),
            $this->arrived('ob-3', 'b-unknown'),
            'не подія',
        ];

        $payload = $this->ingest($events);

        self::assertCount(\count($events), $payload['results']);
        self::assertSame(
            ['applied', 'applied', 'duplicate', 'orphan', 'rejected'],
            array_column($payload['results'], 'outcome'),
        );
        self::assertSame([0, 1, 2, 3, 4], array_column($payload['results'], 'index'));
    }

    #[Test]
    public function bodyWithoutEventsArrayIsRejected(): void
    {
        $this->expectException(MalformedEventException::class);

        ($this->controller)($this->request('{"foo":"bar"}'));
    }

    #[Test]
    public function unparsableBodyIsRejected(): void
    {
        $this->expectException(MalformedEventException::class);

        ($this->controller)($this->request('{'));
    }

    /**
     * Філія без міста в довіднику (наслідок синхронізації MCP) не позбавляє
     * бронювання всіх KPI: факт створюється, а місто отримує явну групу.
     * До цього така подія відхилялася, а решта подій бронювання ставали
     * сиротами — на стенді так загубилися 4 бронювання.
     */
    #[Test]
    public function bookingWithoutCityIsStillProjected(): void
    {
        $created = $this->created('ob-1', 'b1');
        unset($created['payload']['city']);

        $payload = $this->ingest([$created, $this->arrived('ob-2', 'b1')]);

        self::assertSame(2, $payload['applied']);
        self::assertSame(0, $payload['rejected']);
        self::assertSame(0, $payload['orphan']);
        self::assertSame(1, $this->facts->countAll());
        self::assertSame(BookingFact::UNKNOWN_CITY, $this->facts->findByBookingId('b1')?->city);
    }

    /** Порожній рядок — те саме, що відсутнє поле. */
    #[Test]
    public function emptyCityFallsBackToTheExplicitGroup(): void
    {
        $created = $this->created('ob-1', 'b1');
        $created['payload']['city'] = '';

        $this->ingest([$created]);

        self::assertSame(BookingFact::UNKNOWN_CITY, $this->facts->findByBookingId('b1')?->city);
    }

    /**
     * BookingReassigned з payload booking-service застосовується: rampId тепер
     * є в кожній події бронювання. Раніше саме ця подія відхилялася найчастіше.
     */
    #[Test]
    public function reassignmentFromPublisherPayloadIsApplied(): void
    {
        $payload = $this->ingest([
            $this->created('ob-1', 'b1'),
            [
                'eventId' => 'ob-2',
                'name' => 'BookingReassigned',
                'occurredAt' => '2026-03-16T07:00:00Z',
                'payload' => [
                    'bookingId' => 'b1',
                    'storeId' => 'store-1',
                    'city' => 'Київ',
                    'rampId' => 'ramp-2',
                    'supplierId' => 'sup-1',
                    'reason' => 'ramp',
                    'previousRampId' => 'ramp-1',
                ],
            ],
        ]);

        self::assertSame(2, $payload['applied']);
        self::assertSame('ramp-2', $this->facts->findByBookingId('b1')?->rampId());
    }

    /**
     * P-04 наскрізно: після приймання подій дашборд перестає відповідати
     * «Немає даних за обраний період» і показує реальні цифри.
     */
    #[Test]
    public function dashboardStopsReportingNoDataAfterIngest(): void
    {
        $dashboard = new AnalyticsDashboard($this->facts, new InMemorySlotFactRepository());
        $analytics = new AnalyticsController(
            $dashboard,
            new AnalyticsQueryFactory(new FrozenClock(new \DateTimeImmutable('2026-03-20T10:00:00+00:00'))),
        );

        $before = $this->decode($analytics->kpi($this->kpiRequest()));
        self::assertTrue($before['empty']);
        self::assertSame(AnalyticsController::NO_DATA_MESSAGE, $before['message']);

        $this->ingest([
            $this->created('ob-1', 'b1'),
            $this->arrived('ob-2', 'b1'),
            [
                'eventId' => 'ob-3',
                'name' => 'UnloadingStarted',
                'occurredAt' => '2026-03-16T08:05:00Z',
                'payload' => ['bookingId' => 'b1', 'startedAt' => '2026-03-16T08:05:00Z'],
            ],
            [
                'eventId' => 'ob-4',
                'name' => 'UnloadingCompleted',
                'occurredAt' => '2026-03-16T08:25:00Z',
                'payload' => ['bookingId' => 'b1', 'completedAt' => '2026-03-16T08:25:00Z', 'unloadedPalletsCount' => 10],
            ],
        ]);

        $after = $this->decode($analytics->kpi($this->kpiRequest()));

        self::assertFalse($after['empty']);
        self::assertNull($after['message']);
        self::assertNotNull($after['recalculatedAt']);
        self::assertSame(1, $after['kpi']['counters']['total']);
        self::assertSame(1, $after['kpi']['counters']['byStatus']['completed']);
        // KPI-03: чекання від прибуття (07:55) до початку розвантаження (08:05).
        self::assertEquals(10, $after['kpi']['kpi03_waitingTime']['medianMinutes']);
    }

    /**
     * Розріз причин відмов наповнюється з payload видавця. Раніше причина
     * лежала всередині вкладеного `rejectedAt`, тому розріз був порожній.
     */
    #[Test]
    public function rejectionReasonBreakdownFillsFromPublisherPayload(): void
    {
        $analytics = $this->analyticsController();

        $this->ingest([
            $this->created('ob-1', 'b1'),
            $this->arrived('ob-2', 'b1'),
            [
                'eventId' => 'ob-3',
                'name' => 'BookingRejected',
                'occurredAt' => '2026-03-16T08:05:00Z',
                'payload' => [
                    'bookingId' => 'b1',
                    'storeId' => 'store-1',
                    'city' => 'Київ',
                    'rampId' => 'ramp-1',
                    'supplierId' => 'sup-1',
                    'rejectedAt' => '2026-03-16T08:05:00Z',
                    'reason' => 'відсутні документи',
                    'comment' => null,
                ],
            ],
        ]);

        $request = $this->kpiRequest();
        $request->query->set('dimension', 'rejection_reason');
        $breakdown = $this->decode($analytics->breakdown($request));

        self::assertFalse($breakdown['empty']);
        // Причина зіставляється з довідником аналітики, а не звалюється в «Інше».
        self::assertSame([RejectionReason::DocumentsMissing->value], array_column($breakdown['rows'], 'key'));
    }

    /** Кожна причина з довідника видавця має власну групу, а не «Інше». */
    #[Test]
    public function everyPublisherRejectionReasonIsRecognised(): void
    {
        self::assertSame(RejectionReason::WeightExceeded, RejectionReason::fromCode('перевищення тоннажу'));
        self::assertSame(RejectionReason::CargoMismatch, RejectionReason::fromCode('невідповідність вантажу'));
        self::assertSame(RejectionReason::DocumentsMissing, RejectionReason::fromCode('відсутні документи'));
        self::assertSame(RejectionReason::Other, RejectionReason::fromCode('інше'));
        // Машинні коди самої аналітики теж лишаються дійсними.
        self::assertSame(RejectionReason::WeightExceeded, RejectionReason::fromCode('weight_exceeded'));
        // Незнайома причина, як і раніше, не ламає read-модель.
        self::assertSame(RejectionReason::Other, RejectionReason::fromCode('щось нове'));
    }

    /**
     * ANL-04: лічильник часткових розвантажень. Раніше видавець клав під
     * `partialUnload` обʼєкт, а лічильник читає булеве — і завжди бачив false.
     */
    #[Test]
    public function partialUnloadCounterFillsFromPublisherPayload(): void
    {
        $analytics = $this->analyticsController();

        $this->ingest([
            $this->created('ob-1', 'b1'),
            $this->arrived('ob-2', 'b1'),
            [
                'eventId' => 'ob-3',
                'name' => 'UnloadingCompleted',
                'occurredAt' => '2026-03-16T08:25:00Z',
                'payload' => [
                    'bookingId' => 'b1',
                    'storeId' => 'store-1',
                    'city' => 'Київ',
                    'rampId' => 'ramp-1',
                    'supplierId' => 'sup-1',
                    'completedAt' => '2026-03-16T08:25:00Z',
                    'palletsCount' => 10,
                    'unloadedPalletsCount' => 6,
                    'partialUnload' => true,
                    'partialUnloadDetails' => ['flag' => true, 'reason' => 'бій/брак', 'comment' => null],
                ],
            ],
        ]);

        $kpi = $this->decode($analytics->kpi($this->kpiRequest()));

        self::assertSame(1, $kpi['kpi']['counters']['partialUnloadCount']);
        self::assertSame(6, $kpi['kpi']['counters']['unloadedPallets']);
    }

    // --- допоміжне ----------------------------------------------------------

    private function analyticsController(): AnalyticsController
    {
        return new AnalyticsController(
            new AnalyticsDashboard($this->facts, new InMemorySlotFactRepository()),
            new AnalyticsQueryFactory(new FrozenClock(new \DateTimeImmutable('2026-03-20T10:00:00+00:00'))),
        );
    }

    /**
     * @param list<mixed> $events
     *
     * @return array<string, mixed>
     */
    private function ingest(array $events): array
    {
        return $this->decode(($this->controller)(
            $this->request(json_encode(['events' => $events], \JSON_THROW_ON_ERROR)),
        ));
    }

    private function request(string $body): Request
    {
        // Заголовків ідентичності тут немає навмисно: службові маршрути не
        // проходять через auth_request шлюзу.
        return Request::create('/internal/v1/analytics/events', 'POST', content: $body);
    }

    private function kpiRequest(): Request
    {
        $request = Request::create('/api/admin/v1/analytics/kpi', 'GET', [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]);
        $request->headers->set('X-User-Id', 'ad-1');
        $request->headers->set('X-User-Role', 'network_manager');
        $request->headers->set('X-Contour', 'staff');

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function created(string $eventId, string $bookingId): array
    {
        return [
            'eventId' => $eventId,
            'name' => 'BookingCreated',
            'occurredAt' => '2026-03-16T06:00:00Z',
            'payload' => [
                'bookingId' => $bookingId,
                'storeId' => 'store-1',
                'city' => 'Київ',
                'supplierId' => 'sup-1',
                'rampId' => 'ramp-1',
                'slotStart' => '2026-03-16T08:00:00Z',
                'slotEnd' => '2026-03-16T08:30:00Z',
                'type' => 'scheduled',
                'palletsCount' => 10,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrived(string $eventId, string $bookingId): array
    {
        return [
            'eventId' => $eventId,
            'name' => 'BookingArrived',
            'occurredAt' => '2026-03-16T07:55:00Z',
            'payload' => ['bookingId' => $bookingId, 'arrivedAt' => '2026-03-16T07:55:00Z'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
