<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AnalyticsController;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Analytics\AnalyticsDashboard;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Exception\InvalidFilterException;
use App\Domain\Slot\SlotState;
use App\Infrastructure\Http\AnalyticsQueryFactory;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryBookingFactRepository;
use App\Infrastructure\InMemory\InMemorySlotFactRepository;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP API /api/admin/v1/analytics/... — фільтри ANL-10, мітка recalculatedAt
 * (ANL-14), стан «Немає даних» (ANL-13), експорт CSV (ANL-11) і скоуп доступу
 * за службовими заголовками ідентичності (RBAC-13/RBAC-16).
 */
#[CoversClass(AnalyticsController::class)]
#[CoversClass(AnalyticsDashboard::class)]
#[CoversClass(Actor::class)]
final class AnalyticsControllerTest extends TestCase
{
    private AnalyticsController $controller;

    protected function setUp(): void
    {
        $bookings = new InMemoryBookingFactRepository([
            Fixtures::booking(
                bookingId: 'b1',
                storeId: 'store-1',
                city: 'Київ',
                supplierId: 'sup-1',
                slotStart: '2026-03-16 08:00:00',
                slotEnd: '2026-03-16 08:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 07:55:00',
                unloadingStartedAt: '2026-03-16 08:05:00',
                completedAt: '2026-03-16 08:25:00',
                updatedAt: '2026-03-16 08:25:10',
            ),
            Fixtures::booking(
                bookingId: 'b2',
                storeId: 'store-2',
                city: 'Львів',
                supplierId: 'sup-2',
                slotStart: '2026-03-16 09:00:00',
                slotEnd: '2026-03-16 09:30:00',
                type: BookingType::WalkIn,
                status: BookingStatus::NoShow,
                updatedAt: '2026-03-16 10:00:00',
            ),
        ]);

        $slots = new InMemorySlotFactRepository([
            Fixtures::slot('s1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00', storeId: 'store-1', city: 'Київ'),
            Fixtures::slot('s2', SlotState::Available, '2026-03-16 08:30:00', '2026-03-16 09:00:00', storeId: 'store-1', city: 'Київ'),
            Fixtures::slot('s3', SlotState::Blocked, '2026-03-16 09:00:00', '2026-03-16 09:30:00', storeId: 'store-2', city: 'Львів'),
        ]);

        $this->controller = new AnalyticsController(
            new AnalyticsDashboard($bookings, $slots),
            new AnalyticsQueryFactory(new FrozenClock('2026-03-16 12:00:00')),
        );
    }

    #[Test]
    public function kpiEndpointReturnsAllFourCanonicalMetricsAndRecalculatedAt(): void
    {
        $payload = $this->json($this->controller->kpi($this->request(['from' => '2026-03-16', 'to' => '2026-03-16'])));

        self::assertArrayHasKey('kpi01_rampUtilization', $payload['kpi']);
        self::assertArrayHasKey('kpi02_onTimeDelivery', $payload['kpi']);
        self::assertArrayHasKey('kpi03_waitingTime', $payload['kpi']);
        self::assertArrayHasKey('kpi04_noShowRate', $payload['kpi']);
        // 30 заброньованих хв із 60 доступних (blocked виключено) = 50%
        self::assertEqualsWithDelta(50.0, $payload['kpi']['kpi01_rampUtilization']['utilizationPercent'], 0.001);
        self::assertEqualsWithDelta(100.0, $payload['kpi']['kpi02_onTimeDelivery']['onTimePercent'], 0.001);
        self::assertEqualsWithDelta(10.0, $payload['kpi']['kpi03_waitingTime']['averageMinutes'], 0.001);
        self::assertEqualsWithDelta(50.0, $payload['kpi']['kpi04_noShowRate']['noShowPercent'], 0.001);
        self::assertSame('2026-03-16T10:00:00+00:00', $payload['recalculatedAt']);
        self::assertFalse($payload['empty']);
    }

    #[Test]
    public function cityFilterAppliesToAllWidgetsAtOnce(): void
    {
        $payload = $this->json($this->controller->kpi($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'city' => 'Львів',
        ])));

        self::assertSame(1, $payload['kpi']['counters']['total']);
        // у Львові лише blocked-слот → знаменник нульовий, показник відсутній
        self::assertNull($payload['kpi']['kpi01_rampUtilization']['utilizationPercent']);
        self::assertEqualsWithDelta(100.0, $payload['kpi']['kpi04_noShowRate']['noShowPercent'], 0.001);
    }

    #[Test]
    public function emptySelectionIsReportedExplicitly(): void
    {
        $payload = $this->json($this->controller->kpi($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'city' => 'Одеса',
        ])));

        self::assertTrue($payload['empty']);
        self::assertSame('Немає даних за обраний період', $payload['message']);
    }

    #[Test]
    public function breakdownEndpointSupportsSupplierDimension(): void
    {
        $payload = $this->json($this->controller->breakdown($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'dimension' => 'supplier',
        ])));

        self::assertSame('supplier', $payload['dimension']);
        self::assertSame(['sup-1', 'sup-2'], array_column($payload['rows'], 'key'));
    }

    #[Test]
    public function utilizationEndpointRejectsDimensionWithoutSlots(): void
    {
        $this->expectException(InvalidFilterException::class);

        $this->controller->utilization($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'dimension' => 'supplier',
        ]));
    }

    #[Test]
    public function utilizationEndpointGroupsByStore(): void
    {
        $payload = $this->json($this->controller->utilization($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'dimension' => 'store',
        ])));

        self::assertSame(['store-1', 'store-2'], array_column($payload['rows'], 'key'));
        self::assertEqualsWithDelta(50.0, $payload['rows'][0]['utilizationPercent'], 0.001);
        self::assertNull($payload['rows'][1]['utilizationPercent']);
    }

    #[Test]
    public function bookingsEndpointReturnsRowsSortedBySlotStart(): void
    {
        $payload = $this->json($this->controller->bookings($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
        ])));

        self::assertSame(2, $payload['total']);
        self::assertSame(['b1', 'b2'], array_column($payload['rows'], 'bookingId'));
        self::assertEqualsWithDelta(10.0, $payload['rows'][0]['waitingMinutes'], 0.001);
    }

    #[Test]
    public function csvExportReturnsAttachmentWithUtf8ContentType(): void
    {
        $response = $this->controller->exportCsv($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'dataset' => 'bookings',
        ]));

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="analytics-bookings-', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('Фільтри:', (string) $response->getContent());
        self::assertStringContainsString('b1', (string) $response->getContent());
    }

    #[Test]
    public function csvExportRejectsUnknownDataset(): void
    {
        $this->expectException(InvalidFilterException::class);

        $this->controller->exportCsv($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'dataset' => 'усе підряд',
        ]));
    }

    // --- скоуп доступу (RBAC-13/RBAC-16) ---

    #[Test]
    public function storeRoleSeesOnlyItsOwnStoresEvenWithoutStoreFilter(): void
    {
        // Ключовий регрес: раніше запит без ?storeId віддавав магазинній ролі
        // всю мережу, бо порожній фільтр означає «без обмеження».
        $payload = $this->json($this->controller->bookings($this->storeRequest('store-1', [
            'from' => '2026-03-16',
            'to' => '2026-03-16',
        ])));

        self::assertSame(1, $payload['total']);
        self::assertSame(['b1'], array_column($payload['rows'], 'bookingId'));
    }

    #[Test]
    public function storeRoleWithTwoStoresSeesBothAndNothingElse(): void
    {
        $both = $this->json($this->controller->bookings($this->storeRequest('store-1,store-2', [
            'from' => '2026-03-16',
            'to' => '2026-03-16',
        ])));

        self::assertSame(['b1', 'b2'], array_column($both['rows'], 'bookingId'));

        // Третій магазин поза скоупом — відмова, а не тихе розширення вибірки.
        $this->expectException(AccessDeniedException::class);
        $this->controller->bookings($this->storeRequest('store-1,store-2', [
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'storeId' => 'store-3',
        ]));
    }

    #[Test]
    public function storeRoleWithEmptyStoreScopeIsDeniedEverywhere(): void
    {
        $query = ['from' => '2026-03-16', 'to' => '2026-03-16'];

        foreach (['kpi', 'breakdown', 'utilization', 'bookings', 'exportCsv'] as $endpoint) {
            try {
                $this->controller->{$endpoint}($this->storeRequest('', $query));
                self::fail(sprintf('Очікувалася відмова на %s: порожній скоуп магазинів.', $endpoint));
            } catch (AccessDeniedException $exception) {
                self::assertSame('ANALYTICS_ACCESS_DENIED', $exception->errorCode());
                self::assertSame(403, $exception->httpStatus());
            }
        }
    }

    #[Test]
    public function networkRoleSeesEveryStore(): void
    {
        $payload = $this->json($this->controller->bookings($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'storeId' => 'store-2',
        ], ['X-User-Role' => 'analyst'])));

        self::assertSame(['b2'], array_column($payload['rows'], 'bookingId'));

        $all = $this->json($this->controller->bookings($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
        ], ['X-User-Role' => 'super_admin'])));

        self::assertSame(['b1', 'b2'], array_column($all['rows'], 'bookingId'));
    }

    #[Test]
    public function supplierRoleIsDeniedAnalytics(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->controller->kpi($this->request([
            'from' => '2026-03-16',
            'to' => '2026-03-16',
        ], [
            'X-User-Role' => 'supplier_admin',
            'X-Supplier-Id' => 'sup-1',
            'X-Contour' => 'partner',
        ]));
    }

    #[Test]
    public function supplierRoleWithEmptySupplierHeaderIsDenied(): void
    {
        try {
            $this->controller->kpi($this->request([
                'from' => '2026-03-16',
                'to' => '2026-03-16',
            ], [
                'X-User-Role' => 'supplier_operator',
                'X-Supplier-Id' => '',
                'X-Contour' => 'partner',
            ]));
            self::fail('Очікувалася відмова: постачальник без X-Supplier-Id.');
        } catch (AccessDeniedException $exception) {
            self::assertStringContainsString('X-Supplier-Id', $exception->getMessage());
        }
    }

    #[Test]
    public function requestWithoutIdentityHeadersIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->controller->kpi(Request::create('/api/admin/v1/analytics/kpi', 'GET', [
            'from' => '2026-03-16',
            'to' => '2026-03-16',
        ]));
    }

    #[Test]
    public function storeScopeAlsoConstrainsCsvExport(): void
    {
        $response = $this->controller->exportCsv($this->storeRequest('store-2', [
            'from' => '2026-03-16',
            'to' => '2026-03-16',
            'dataset' => 'bookings',
        ]));

        $csv = (string) $response->getContent();

        self::assertStringContainsString('b2', $csv);
        self::assertStringNotContainsString('b1', $csv);
    }

    /**
     * За замовчуванням — мережева роль (скоуп «уся мережа»), щоб перевірки
     * фільтрів і форматів не залежали від RBAC.
     *
     * @param array<string, string|list<string>> $query
     * @param array<string, string>              $headers
     */
    private function request(array $query, array $headers = []): Request
    {
        $request = Request::create('/api/admin/v1/analytics/kpi', 'GET', $query);

        $headers += [
            'X-User-Id' => 'u-admin',
            'X-User-Role' => 'network_manager',
            'X-Supplier-Id' => '',
            'X-Store-Ids' => '',
            'X-Contour' => 'staff',
        ];

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    /**
     * Запит від імені магазинної ролі з переліком магазинів у X-Store-Ids
     * (порожній рядок = жодного магазину).
     *
     * @param array<string, string|list<string>> $query
     */
    private function storeRequest(string $storeIds, array $query): Request
    {
        return $this->request($query, [
            'X-User-Id' => 'u-store',
            'X-User-Role' => 'store_manager',
            'X-Store-Ids' => $storeIds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
