<?php

declare(strict_types=1);

namespace App\Tests\Domain\Branch;

use App\Domain\Branch\BranchEligibility;
use App\Domain\Branch\IneligibilityReason;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Правила фільтрації записів MCP (fixtures/README.md).
 */
#[CoversClass(BranchEligibility::class)]
final class BranchEligibilityTest extends TestCase
{
    public function testValidKyivBranchIsEligible(): void
    {
        self::assertSame([], BranchEligibility::evaluate(BranchFactory::mcpData()));
        self::assertTrue(BranchEligibility::isEligible(BranchFactory::mcpData()));
    }

    public function testDeletedExternalIdIsRejected(): void
    {
        $data = BranchFactory::mcpData(['externalId' => 'delete_filia_silpo_ivasuka46']);

        self::assertContains(IneligibilityReason::DeletedExternalId, BranchEligibility::evaluate($data));
    }

    public function testEmptyCityAndAddressAreBothReported(): void
    {
        $data = BranchFactory::mcpData(['city' => '', 'address' => '   ']);
        $reasons = BranchEligibility::evaluate($data);

        self::assertContains(IneligibilityReason::EmptyCity, $reasons);
        self::assertContains(IneligibilityReason::EmptyAddress, $reasons);
    }

    public function testMissingCoordinatesAreRejected(): void
    {
        $data = BranchFactory::mcpData(['latitude' => null, 'longitude' => null]);

        self::assertSame([IneligibilityReason::MissingCoordinates], BranchEligibility::evaluate($data));
    }

    /**
     * Тестові філії з фікстури: 3656 (50.1, 49.2), 567898 (1.0, 1.0), 791091 (Нью-Йорк).
     */
    #[DataProvider('outsideUkraineProvider')]
    public function testCoordinatesOutsideUkraineAreRejected(float $lat, float $lng): void
    {
        $data = BranchFactory::mcpData(['latitude' => $lat, 'longitude' => $lng]);

        self::assertSame([IneligibilityReason::CoordinatesOutsideUkraine], BranchEligibility::evaluate($data));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function outsideUkraineProvider(): iterable
    {
        yield 'довгота за східною межею' => [50.1, 49.2];
        yield 'нульовий острів' => [1.0, 1.0];
        yield 'Волл-стріт' => [40.7069, -74.0113];
    }

    /**
     * Межі bbox України включно: 44.0–52.5 / 22.0–40.5.
     */
    #[DataProvider('bboxEdgeProvider')]
    public function testBoundingBoxEdgesAreInclusive(float $lat, float $lng, bool $expected): void
    {
        $data = BranchFactory::mcpData(['latitude' => $lat, 'longitude' => $lng]);

        self::assertSame($expected, BranchEligibility::isEligible($data));
    }

    /**
     * @return iterable<string, array{float, float, bool}>
     */
    public static function bboxEdgeProvider(): iterable
    {
        yield 'південно-західний кут' => [44.0, 22.0, true];
        yield 'північно-східний кут' => [52.5, 40.5, true];
        yield 'на 0.1 південніше' => [43.9, 30.0, false];
        yield 'на 0.1 східніше' => [50.0, 40.6, false];
    }

    public function testReasonsAreAccumulatedForOneRecord(): void
    {
        $data = BranchFactory::mcpData([
            'externalId' => 'delete_test',
            'city' => '',
            'address' => '',
            'latitude' => null,
            'longitude' => null,
        ]);

        self::assertCount(4, BranchEligibility::evaluate($data));
    }
}
