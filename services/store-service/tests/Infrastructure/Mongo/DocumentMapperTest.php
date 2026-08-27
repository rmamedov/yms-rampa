<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Branch\Branch;
use App\Domain\Branch\IneligibilityReason;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Shared\Uuid;
use App\Infrastructure\Mongo\Mapper\BranchDocumentMapper;
use App\Infrastructure\Mongo\Mapper\ConfigurationDocumentMapper;
use App\Infrastructure\Mongo\MongoConnection;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Мапінг доменних обʼєктів у документи MongoDB (схеми 10.2.1–10.2.3).
 *
 * Сервер MongoDB не потрібен, але потрібне розширення ext-mongodb (типи BSON),
 * тому за його відсутності тести пропускаються.
 */
#[Group('integration')]
#[CoversClass(BranchDocumentMapper::class)]
#[CoversClass(ConfigurationDocumentMapper::class)]
final class DocumentMapperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!MongoConnection::isAvailable()) {
            self::markTestSkipped('Розширення PHP ext-mongodb не встановлено.');
        }
    }

    /** Документ філії відповідає схемі 10.2.1, включно з GeoJSON Point. */
    public function testBranchDocumentMatchesSchema(): void
    {
        $branch = BranchFactory::branch();
        $document = BranchDocumentMapper::toDocument($branch);

        self::assertSame(BranchFactory::KYIV_ID, $document['_id']);
        self::assertSame('1998', $document['externalId']);
        self::assertSame('Point', $document['location']['type']);
        self::assertEqualsWithDelta(30.51452, $document['location']['coordinates'][0], 0.000001);
        self::assertSame('not_configured', $document['ymsStatus']);
        self::assertFalse($document['visibleToSuppliers']);
        self::assertSame(0, $document['missingSyncCount']);
        self::assertSame(Branch::SCHEMA_VERSION, $document['schemaVersion']);
        self::assertNull($document['archivedAt']);
    }

    public function testBranchRoundTripThroughDocument(): void
    {
        $now = new \DateTimeImmutable('2026-08-27T08:00:00+00:00');
        $branch = BranchFactory::branch();
        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration()->readiness(), $now);
        $branch->setVisibleToSuppliers(true, $now);
        $branch->setAddressOverride('вʼїзд з двору', $now);

        $restored = BranchDocumentMapper::fromDocument(BranchDocumentMapper::toDocument($branch));

        self::assertSame($branch->id(), $restored->id());
        self::assertSame(YmsStatus::Active, $restored->ymsStatus());
        self::assertTrue($restored->visibleToSuppliers());
        self::assertSame('вʼїзд з двору', $restored->effectiveAddress());
        self::assertTrue($restored->isEligible());
    }

    public function testIneligibilityReasonsRoundTrip(): void
    {
        $branch = BranchFactory::branch(['externalId' => 'delete_x', 'latitude' => null, 'longitude' => null]);

        $restored = BranchDocumentMapper::fromDocument(BranchDocumentMapper::toDocument($branch));

        self::assertContains(IneligibilityReason::DeletedExternalId, $restored->ineligibilityReasons());
        self::assertContains(IneligibilityReason::MissingCoordinates, $restored->ineligibilityReasons());
        self::assertNull($restored->mcpData()->location);
    }

    /** Документ конфігурації відповідає схемі 10.2.2 (поле horizonDays). */
    public function testConfigurationDocumentMatchesSchema(): void
    {
        $config = BranchFactory::completeConfiguration();
        $document = ConfigurationDocumentMapper::configToDocument($config);

        self::assertSame(30, $document['slotSizeMinutes']);
        self::assertSame(14, $document['horizonDays']);
        self::assertCount(3, $document['ramps']);
        self::assertSame('r1', $document['ramps'][0]['rampId']);
        self::assertSame(StoreConfiguration::SCHEMA_VERSION, $document['schemaVersion']);
    }

    public function testConfigurationRoundTrip(): void
    {
        $config = BranchFactory::completeConfiguration(maxWeight: 12.5);
        $restored = ConfigurationDocumentMapper::configFromDocument(
            ConfigurationDocumentMapper::configToDocument($config),
        );

        self::assertSame(12.5, $restored->maxVehicleWeightTons);
        self::assertSame(30, $restored->slotSize->value);
        self::assertCount(2, $restored->activeRamps());
        self::assertTrue($restored->isComplete());
        self::assertSame('06:00', $restored->intervalsForLocalDate('2026-08-31')[0]->from);
    }

    public function testSlotBlockRoundTrip(): void
    {
        $block = new SlotBlock(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            rampIds: ['r1', 'r2'],
            blockFrom: new \DateTimeImmutable('2026-09-01T06:00:00+00:00'),
            blockTo: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            reason: 'Ремонт рампи',
        );

        $restored = ConfigurationDocumentMapper::blockFromDocument(
            ConfigurationDocumentMapper::blockToDocument($block),
        );

        self::assertSame(['r1', 'r2'], $restored->rampIds);
        self::assertSame('Ремонт рампи', $restored->reason);
        self::assertFalse($restored->isReleased());
        self::assertSame(
            $block->blockFrom->format(\DATE_ATOM),
            $restored->blockFrom->format(\DATE_ATOM),
        );
    }

    public function testReservedSlotRuleRoundTripKeepsXorFields(): void
    {
        $rule = new \App\Domain\Configuration\ReservedSlotRule(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            supplierId: 'supplier-1',
            rampId: 'r1',
            slotStartTime: '08:00',
            dayOfWeek: null,
            date: '2026-09-01',
            validFrom: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $restored = ConfigurationDocumentMapper::ruleFromDocument(
            ConfigurationDocumentMapper::ruleToDocument($rule),
        );

        self::assertNull($restored->dayOfWeek);
        self::assertSame('2026-09-01', $restored->date);
        self::assertSame(2, $restored->effectiveDayOfWeek());
    }
}
