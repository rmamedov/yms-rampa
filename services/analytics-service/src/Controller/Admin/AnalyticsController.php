<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Analytics\AnalyticsDashboard;
use App\Domain\Exception\InvalidFilterException;
use App\Domain\Fact\BookingFact;
use App\Domain\Kpi\Statistics;
use App\Infrastructure\Export\AnalyticsCsvView;
use App\Infrastructure\Http\AnalyticsQueryFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP API аналітики staff-контуру: /api/admin/v1/analytics/...
 *
 * Усі ендпоінти — read-only (ANL-12: для analyst дашборди лише для читання;
 * обмеження store_manager його магазинами виконує api-gateway, підставляючи
 * фільтр storeId). Кожна відповідь несе мітку recalculatedAt (ANL-14) та,
 * за відсутності даних, явний стан «Немає даних за обраний період» (ANL-13).
 *
 * Помилки фільтрів повертаються у форматі RFC 7807 application/problem+json
 * (див. ProblemJsonExceptionListener).
 */
#[AsController]
#[Route('/api/admin/v1/analytics')]
final readonly class AnalyticsController
{
    public const NO_DATA_MESSAGE = 'Немає даних за обраний період';

    public function __construct(
        private AnalyticsDashboard $dashboard,
        private AnalyticsQueryFactory $queryFactory,
        private AnalyticsCsvView $csvView = new AnalyticsCsvView(),
    ) {
    }

    /**
     * KPI-01…KPI-04 одним зведенням за фільтрами ANL-10.
     */
    #[Route('/kpi', name: 'admin_analytics_kpi', methods: ['GET'])]
    public function kpi(Request $request): JsonResponse
    {
        $query = $this->queryFactory->fromRequest($request);
        $summary = $this->dashboard->summary($query);

        return $this->json([
            'filters' => $query->describe(),
            'kpi' => $summary->toArray(),
        ], $summary->hasData());
    }

    /**
     * KPI-05: розрізи мережа / місто / магазин / рампа / постачальник /
     * день-тиждень-місяць, тип бронювання, причини відмов.
     */
    #[Route('/breakdown', name: 'admin_analytics_breakdown', methods: ['GET'])]
    public function breakdown(Request $request): JsonResponse
    {
        $query = $this->queryFactory->fromRequest($request);
        $dimension = $this->queryFactory->dimensionFromRequest($request);
        $rows = $this->dashboard->breakdown($query, $dimension);

        return $this->json([
            'filters' => $query->describe(),
            'dimension' => $dimension->value,
            'dimensionLabel' => $dimension->label(),
            'rows' => array_map(static fn ($row): array => $row->toArray(), $rows),
        ], $rows !== []);
    }

    /**
     * ANL-01: утилізація слотів за канонічною формулою KPI-01,
     * розрізи магазин / рампа / день.
     */
    #[Route('/utilization', name: 'admin_analytics_utilization', methods: ['GET'])]
    public function utilization(Request $request): JsonResponse
    {
        $query = $this->queryFactory->fromRequest($request);
        $dimension = $this->queryFactory->dimensionFromRequest($request);
        $groups = $this->dashboard->utilization($query, $dimension);

        $rows = [];
        foreach ($groups as $key => $result) {
            $rows[] = ['key' => $key] + $result->toArray();
        }

        return $this->json([
            'filters' => $query->describe(),
            'dimension' => $dimension->value,
            'rows' => $rows,
        ], $rows !== []);
    }

    /**
     * ANL-02: рядки вибірки бронювань (поставки по постачальниках/магазинах).
     */
    #[Route('/bookings', name: 'admin_analytics_bookings', methods: ['GET'])]
    public function bookings(Request $request): JsonResponse
    {
        $query = $this->queryFactory->fromRequest($request);
        $facts = $this->dashboard->bookings($query);

        return $this->json([
            'filters' => $query->describe(),
            'total' => count($facts),
            'rows' => array_map($this->factToArray(...), $facts),
        ], $facts !== []);
    }

    /**
     * ANL-11: експорт поточної вибірки у CSV (UTF-8, роздільник кома,
     * застосовані фільтри окремим рядком-заголовком, без пагінації).
     */
    #[Route('/export.csv', name: 'admin_analytics_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $query = $this->queryFactory->fromRequest($request);
        $dataset = (string) ($request->query->get('dataset') ?? 'bookings');

        $csv = match ($dataset) {
            'bookings' => $this->csvView->bookings($query, $this->dashboard->bookings($query)),
            'breakdown' => $this->csvView->breakdown(
                $query,
                $this->dashboard->breakdown($query, $this->queryFactory->dimensionFromRequest($request)),
            ),
            default => throw InvalidFilterException::invalidEnum(sprintf(
                'Невідомий набір даних для експорту «%s». Доступні: bookings, breakdown.',
                $dataset,
            )),
        };

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="analytics-%s-%s.csv"', $dataset, $query->from->format('Y-m-d')),
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function factToArray(BookingFact $fact): array
    {
        return [
            'bookingId' => $fact->bookingId,
            'storeId' => $fact->storeId,
            'city' => $fact->city,
            'supplierId' => $fact->supplierId,
            'rampId' => $fact->rampId(),
            'slotStart' => $fact->slotStart->format(\DATE_ATOM),
            'slotEnd' => $fact->slotEnd->format(\DATE_ATOM),
            'type' => $fact->type->value,
            'status' => $fact->status()->value,
            'arrivedAt' => $fact->arrivedAt()?->format(\DATE_ATOM),
            'unloadingStartedAt' => $fact->unloadingStartedAt()?->format(\DATE_ATOM),
            'completedAt' => $fact->completedAt()?->format(\DATE_ATOM),
            'waitingMinutes' => Statistics::round($fact->waitingMinutes()),
            'unloadingMinutes' => Statistics::round($fact->unloadingMinutes()),
            'palletsCount' => $fact->palletsCount,
            'unloadedPalletsCount' => $fact->unloadedPalletsCount(),
            'partialUnload' => $fact->isPartialUnload(),
            'delayed' => $fact->isDelayed(),
            'delayReason' => $fact->delayReason(),
            'rejectedReason' => $fact->rejectedReason()?->value,
            'rescheduleOf' => $fact->rescheduleOf,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, bool $hasData): JsonResponse
    {
        $recalculatedAt = $this->dashboard->recalculatedAt();

        return new JsonResponse($payload + [
            'recalculatedAt' => $recalculatedAt?->format(\DATE_ATOM),
            'empty' => !$hasData,
            'message' => $hasData ? null : self::NO_DATA_MESSAGE,
        ]);
    }
}
