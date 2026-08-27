<?php

declare(strict_types=1);

namespace App\Tests\Domain\Branch;

use App\Domain\Branch\McpData;
use App\Domain\Shared\ValidationException;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Контракт блоку mcpData (11.1.1, INT-14) і нормалізація полів (fixtures/README.md).
 */
#[CoversClass(McpData::class)]
final class McpDataTest extends TestCase
{
    /** hasPickup=null нормалізується у false, інакше фільтр поводиться непередбачувано. */
    public function testNullHasPickupIsNormalisedToFalse(): void
    {
        $data = BranchFactory::mcpData(['hasPickup' => null]);

        self::assertFalse($data->hasPickup);
    }

    public function testMissingHasPickupKeyIsNormalisedToFalse(): void
    {
        $row = BranchFactory::mcpRow();
        unset($row['hasPickup']);

        self::assertFalse(McpData::fromMcpRow($row)->hasPickup);
    }

    /** INT-14: запис без branchId відхиляється на рівні запису. */
    public function testRowWithoutBranchIdIsRejected(): void
    {
        $row = BranchFactory::mcpRow();
        unset($row['branchId']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('без branchId');

        McpData::fromMcpRow($row);
    }

    public function testRowWithInvalidUuidIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('валідним UUID');

        McpData::fromMcpRow(BranchFactory::mcpRow(['branchId' => 'not-a-uuid']));
    }

    /** Координати з MCP приходять рядками — мають розбиратись у float. */
    public function testStringCoordinatesAreParsed(): void
    {
        $data = BranchFactory::mcpData();

        self::assertNotNull($data->location);
        self::assertEqualsWithDelta(50.52022, $data->location->latitude, 0.000001);
        self::assertEqualsWithDelta(30.51452, $data->location->longitude, 0.000001);
    }

    /** SYNC-03: diff містить старе і нове значення кожного зміненого поля. */
    public function testDiffReportsOldAndNewValues(): void
    {
        $before = BranchFactory::mcpData();
        $after = BranchFactory::mcpData(['city' => 'Львів', 'open' => false]);

        $diff = $before->diff($after);

        self::assertSame(['old' => 'Київ', 'new' => 'Львів'], $diff['city']);
        self::assertSame(['old' => true, 'new' => false], $diff['open']);
        self::assertArrayNotHasKey('address', $diff);
    }

    public function testDiffDetectsCoordinateChange(): void
    {
        $before = BranchFactory::mcpData();
        $after = BranchFactory::mcpData(['latitude' => '49.8397']);

        self::assertArrayHasKey('location', $before->diff($after));
    }

    public function testIdenticalRecordsAreEqual(): void
    {
        self::assertTrue(BranchFactory::mcpData()->equals(BranchFactory::mcpData()));
    }

    /** GeoJSON зберігається у порядку [lng, lat] (10.2.1). */
    public function testGeoJsonUsesLongitudeFirst(): void
    {
        $geoJson = BranchFactory::mcpData()->location?->toGeoJson();

        self::assertSame('Point', $geoJson['type']);
        self::assertEqualsWithDelta(30.51452, $geoJson['coordinates'][0], 0.000001);
        self::assertEqualsWithDelta(50.52022, $geoJson['coordinates'][1], 0.000001);
    }
}
