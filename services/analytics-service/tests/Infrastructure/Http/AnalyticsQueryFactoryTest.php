<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Analytics\Dimension;
use App\Domain\Booking\BookingType;
use App\Domain\Exception\InvalidFilterException;
use App\Infrastructure\Http\AnalyticsQueryFactory;
use App\Infrastructure\InMemory\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * ANL-10: період (з/по, пресети «сьогодні», «7 днів», «30 днів»), місто,
 * магазин (мультивибір), постачальник (мультивибір).
 */
#[CoversClass(AnalyticsQueryFactory::class)]
final class AnalyticsQueryFactoryTest extends TestCase
{
    private AnalyticsQueryFactory $factory;

    protected function setUp(): void
    {
        // 16 березня 2026, 09:00 UTC = 11:00 за Києвом
        $this->factory = new AnalyticsQueryFactory(new FrozenClock('2026-03-16 09:00:00'));
    }

    #[Test]
    public function parsesExplicitPeriodAsInclusiveLocalDaysConvertedToUtc(): void
    {
        $query = $this->factory->fromRequest(Request::create('/api/admin/v1/analytics/kpi', 'GET', [
            'from' => '2026-03-16',
            'to' => '2026-03-16',
        ]));

        // 00:00 за Києвом 16 березня = 22:00 UTC 15 березня (EET, UTC+2)
        self::assertSame('2026-03-15 22:00:00', $query->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 22:00:00', $query->to->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function todayPresetCoversCurrentLocalDay(): void
    {
        $query = $this->factory->fromRequest(Request::create('/x', 'GET', ['preset' => 'today']));

        self::assertSame('2026-03-15 22:00:00', $query->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 22:00:00', $query->to->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function sevenAndThirtyDayPresetsIncludeToday(): void
    {
        $week = $this->factory->fromRequest(Request::create('/x', 'GET', ['preset' => '7d']));
        $month = $this->factory->fromRequest(Request::create('/x', 'GET', ['preset' => '30d']));

        self::assertSame('2026-03-09 22:00:00', $week->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-14 22:00:00', $month->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 22:00:00', $week->to->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function readsMultiSelectFiltersFromRepeatedAndCommaSeparatedParameters(): void
    {
        $query = $this->factory->fromRequest(Request::create('/x', 'GET', [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'city' => 'Київ,Львів',
            'storeId' => ['store-1', 'store-2'],
            'supplierId' => 'sup-1',
            'type' => 'walk_in',
        ]));

        self::assertSame(['Київ', 'Львів'], $query->cities);
        self::assertSame(['store-1', 'store-2'], $query->storeIds);
        self::assertSame(['sup-1'], $query->supplierIds);
        self::assertSame([BookingType::WalkIn], $query->types);
    }

    #[Test]
    public function missingPeriodIsRejectedWithDomainErrorCode(): void
    {
        try {
            $this->factory->fromRequest(Request::create('/x'));
            self::fail('Очікувалася помилка фільтра.');
        } catch (InvalidFilterException $exception) {
            self::assertSame('ANALYTICS_INVALID_PERIOD', $exception->errorCode());
            self::assertSame(422, $exception->httpStatus());
        }
    }

    #[Test]
    public function reversedPeriodIsRejected(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Початок періоду не може бути пізнішим за кінець.');

        $this->factory->fromRequest(Request::create('/x', 'GET', ['from' => '2026-03-20', 'to' => '2026-03-10']));
    }

    #[Test]
    public function tooLongPeriodIsRejected(): void
    {
        try {
            $this->factory->fromRequest(Request::create('/x', 'GET', ['from' => '2020-01-01', 'to' => '2026-01-01']));
            self::fail('Очікувалася помилка занадто довгого періоду.');
        } catch (InvalidFilterException $exception) {
            self::assertSame('ANALYTICS_PERIOD_TOO_LONG', $exception->errorCode());
        }
    }

    #[Test]
    public function unknownPresetIsRejected(): void
    {
        $this->expectException(InvalidFilterException::class);

        $this->factory->fromRequest(Request::create('/x', 'GET', ['preset' => 'весь час']));
    }

    #[Test]
    public function unknownBookingTypeIsRejected(): void
    {
        try {
            $this->factory->fromRequest(Request::create('/x', 'GET', [
                'from' => '2026-03-01',
                'to' => '2026-03-02',
                'type' => 'express',
            ]));
            self::fail('Очікувалася помилка фільтра типу.');
        } catch (InvalidFilterException $exception) {
            self::assertSame('ANALYTICS_INVALID_FILTER', $exception->errorCode());
        }
    }

    #[Test]
    public function parsesDimensionAndRejectsUnknownOne(): void
    {
        self::assertSame(
            Dimension::Supplier,
            $this->factory->dimensionFromRequest(Request::create('/x', 'GET', ['dimension' => 'supplier'])),
        );
        self::assertSame(Dimension::Store, $this->factory->dimensionFromRequest(Request::create('/x')));

        try {
            $this->factory->dimensionFromRequest(Request::create('/x', 'GET', ['dimension' => 'галактика']));
            self::fail('Очікувалася помилка розрізу.');
        } catch (InvalidFilterException $exception) {
            self::assertSame('ANALYTICS_INVALID_DIMENSION', $exception->errorCode());
        }
    }
}
