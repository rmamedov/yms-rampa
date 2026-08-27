<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kpi;

use App\Domain\Kpi\Statistics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Statistics::class)]
final class StatisticsTest extends TestCase
{
    /**
     * @param list<float> $values
     */
    #[Test]
    #[DataProvider('medianCases')]
    public function computesMedianIndependentlyOfInputOrder(array $values, ?float $expected): void
    {
        self::assertSame($expected, Statistics::median($values));
    }

    /**
     * @return iterable<string, array{list<float>, ?float}>
     */
    public static function medianCases(): iterable
    {
        yield 'порожній набір' => [[], null];
        yield 'одне значення' => [[7.0], 7.0];
        yield 'непарна кількість, невідсортовано' => [[30.0, 10.0, 20.0], 20.0];
        yield 'парна кількість, невідсортовано' => [[40.0, 10.0, 30.0, 20.0], 25.0];
    }

    #[Test]
    public function percentReturnsNullOnZeroDenominatorInsteadOfDividingByZero(): void
    {
        self::assertNull(Statistics::percent(5.0, 0.0));
        self::assertNull(Statistics::percent(0.0, 0.0));
        self::assertSame(50.0, Statistics::percent(1.0, 2.0));
    }

    #[Test]
    public function averageOfEmptySetIsNull(): void
    {
        self::assertNull(Statistics::average([]));
        self::assertSame(2.0, Statistics::average([1.0, 2.0, 3.0]));
    }

    #[Test]
    public function roundKeepsNull(): void
    {
        self::assertNull(Statistics::round(null));
        self::assertSame(33.33, Statistics::round(100 / 3));
    }
}
