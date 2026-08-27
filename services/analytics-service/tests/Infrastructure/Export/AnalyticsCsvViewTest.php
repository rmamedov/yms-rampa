<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Export;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\BreakdownCalculator;
use App\Domain\Analytics\Dimension;
use App\Domain\Booking\BookingStatus;
use App\Infrastructure\Export\AnalyticsCsvView;
use App\Infrastructure\Export\CsvExporter;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ANL-11: експорт вибірки у CSV — UTF-8, роздільник кома, застосовані фільтри
 * окремим рядком-заголовком, усі рядки вибірки без пагінації.
 */
#[CoversClass(AnalyticsCsvView::class)]
#[CoversClass(CsvExporter::class)]
final class AnalyticsCsvViewTest extends TestCase
{
    private AnalyticsCsvView $view;
    private AnalyticsQuery $query;

    protected function setUp(): void
    {
        $this->view = new AnalyticsCsvView();
        $this->query = new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
            cities: ['Київ'],
        );
    }

    #[Test]
    public function firstLineCarriesAppliedFiltersAndSecondIsTableHeader(): void
    {
        $lines = $this->lines($this->view->bookings($this->query, [Fixtures::booking()]));

        self::assertStringStartsWith('"Фільтри: період: 2026-03-16 00:00', $lines[0]);
        self::assertStringContainsString('міста: Київ', $lines[0]);
        self::assertStringStartsWith('bookingId,', $lines[1]);
    }

    #[Test]
    public function exportsEveryRowOfSelectionWithoutPagination(): void
    {
        $facts = [];
        for ($i = 1; $i <= 120; ++$i) {
            $facts[] = Fixtures::booking(bookingId: 'b' . $i);
        }

        $lines = $this->lines($this->view->bookings($this->query, $facts));

        // рядок фільтрів + заголовок + 120 рядків даних
        self::assertCount(122, $lines);
    }

    #[Test]
    public function marksOnTimeArrivalAccordingToKpi02(): void
    {
        $csv = $this->view->bookings($this->query, [
            Fixtures::booking(
                bookingId: 'on-time',
                slotStart: '2026-03-16 10:00:00',
                slotEnd: '2026-03-16 10:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 09:45:00',
            ),
            Fixtures::booking(
                bookingId: 'late',
                slotStart: '2026-03-16 10:00:00',
                slotEnd: '2026-03-16 10:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 11:00:00',
            ),
            Fixtures::booking(bookingId: 'no-show', status: BookingStatus::NoShow),
        ]);

        $lines = $this->lines($csv);

        self::assertStringContainsString(',так,', $lines[2]);
        self::assertStringContainsString(',ні,', $lines[3]);
        self::assertStringContainsString(',н/д,', $lines[4]);
    }

    #[Test]
    public function usesCommaDelimiterAndUtf8Encoding(): void
    {
        $csv = $this->view->bookings($this->query, [Fixtures::booking(bookingId: 'b1')]);

        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
        self::assertStringContainsString('Київ', $csv);
        self::assertGreaterThan(10, substr_count($this->lines($csv)[2], ','));
    }

    #[Test]
    public function breakdownExportContainsKpiColumns(): void
    {
        $rows = (new BreakdownCalculator())->calculate(
            [
                Fixtures::booking(bookingId: 'k1', status: BookingStatus::Completed, arrivedAt: '2026-03-16 07:55:00', unloadingStartedAt: '2026-03-16 08:05:00'),
                Fixtures::booking(bookingId: 'k2', status: BookingStatus::NoShow),
            ],
            [],
            Dimension::Store,
        );

        $lines = $this->lines($this->view->breakdown($this->query, $rows));

        self::assertStringContainsString('KPI-01 утилізація, %', $lines[1]);
        self::assertStringContainsString('KPI-04 no-show, %', $lines[1]);
        self::assertStringContainsString('store-1', $lines[2]);
        // KPI-04: 1 no_show із 2 бронювань = 50
        self::assertStringContainsString(',50,', $lines[2]);
    }

    #[Test]
    public function emptySelectionStillProducesFiltersAndHeaderLines(): void
    {
        $lines = $this->lines($this->view->bookings($this->query, []));

        self::assertCount(2, $lines);
    }

    /**
     * @return list<string>
     */
    private function lines(string $csv): array
    {
        return array_values(array_filter(explode("\n", trim($csv)), static fn (string $l): bool => $l !== ''));
    }
}
