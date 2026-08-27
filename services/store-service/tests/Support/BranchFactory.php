<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Branch\Branch;
use App\Domain\Branch\GeoLocation;
use App\Domain\Branch\McpData;
use App\Domain\Configuration\CalendarException;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\ReceivingWindow;
use App\Domain\Configuration\SlotSize;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\TimeInterval;
use App\Domain\Shared\Uuid;

/**
 * Фабрики тестових даних: валідна київська філія і повна конфігурація магазину.
 */
final class BranchFactory
{
    public const string KYIV_ID = '1ed43e73-051b-6842-a111-a5ad042eb496';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function mcpRow(array $overrides = []): array
    {
        return array_replace([
            'branchId' => self::KYIV_ID,
            'companyId' => '1ec88c5d-a050-669c-8467-570a157f3e31',
            'externalId' => '1998',
            'city' => 'Київ',
            'address' => 'просп. Володимира Івасюка, 46',
            'latitude' => '50.5202200000000000',
            'longitude' => '30.5145200000000000',
            'hasPickup' => true,
            'open' => true,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function mcpData(array $overrides = []): McpData
    {
        return McpData::fromMcpRow(self::mcpRow($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function branch(array $overrides = [], ?\DateTimeImmutable $syncedAt = null): Branch
    {
        return Branch::createFromMcp(
            self::mcpData($overrides),
            $syncedAt ?? new \DateTimeImmutable('2026-08-27T03:00:00+00:00'),
        );
    }

    public static function location(float $lat = 50.45, float $lng = 30.52): GeoLocation
    {
        return new GeoLocation($lat, $lng);
    }

    /**
     * Повна конфігурація, що задовольняє STL-04: є вікна прийому, розмір слоту,
     * активна рампа і maxVehicleWeightTons.
     */
    public static function completeConfiguration(
        string $storeId = self::KYIV_ID,
        int $version = 1,
        ?\DateTimeImmutable $effectiveFrom = null,
        float $maxWeight = 10.0,
        SlotSize $slotSize = SlotSize::Half,
    ): StoreConfiguration {
        return new StoreConfiguration(
            id: Uuid::v4(),
            storeId: $storeId,
            version: $version,
            effectiveFrom: $effectiveFrom ?? new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            receivingWindows: [
                new ReceivingWindow(1, [new TimeInterval('06:00', '12:00')]),
                new ReceivingWindow(2, [new TimeInterval('06:00', '12:00'), new TimeInterval('14:00', '18:00')]),
                new ReceivingWindow(3, [new TimeInterval('06:00', '12:00')]),
                new ReceivingWindow(4, [new TimeInterval('06:00', '12:00')]),
                new ReceivingWindow(5, [new TimeInterval('06:00', '12:00')]),
            ],
            slotSize: $slotSize,
            ramps: [
                new Ramp('r1', 1, 'Рампа 1'),
                new Ramp('r2', 2, 'Рампа 2'),
                new Ramp('r3', 3, 'Рампа 3 (у ремонті)', false),
            ],
            maxVehicleWeightTons: $maxWeight,
            createdBy: 'staff-1',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    /** Конфігурація без жодного вікна прийому — магазин НЕ «налаштований» за STL-04. */
    public static function incompleteConfiguration(string $storeId = self::KYIV_ID): StoreConfiguration
    {
        return new StoreConfiguration(
            id: Uuid::v4(),
            storeId: $storeId,
            version: 1,
            effectiveFrom: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            receivingWindows: [],
            slotSize: SlotSize::Half,
            ramps: [new Ramp('r1', 1, 'Рампа 1', false)],
            maxVehicleWeightTons: 10.0,
        );
    }

    public static function holiday(string $date = '2026-12-31'): CalendarException
    {
        return new CalendarException($date, \App\Domain\Configuration\CalendarExceptionType::Closed, 'Інвентаризація');
    }
}
