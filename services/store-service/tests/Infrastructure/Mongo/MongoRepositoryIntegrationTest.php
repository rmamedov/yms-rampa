<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchCriteria;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\StoreConfiguration;
use App\Infrastructure\Mongo\MongoBranchRepository;
use App\Infrastructure\Mongo\MongoConnection;
use App\Infrastructure\Mongo\MongoIndexInitializer;
use App\Infrastructure\Mongo\MongoStoreConfigurationRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Інтеграційні тести Mongo-реалізації репозиторіїв (10.2).
 *
 * Виконуються лише за наявності розширення ext-mongodb ТА живого сервера MongoDB.
 * На машині без MongoDB тест не падає, а помічається як пропущений.
 */
#[Group('integration')]
#[CoversClass(MongoBranchRepository::class)]
#[CoversClass(MongoStoreConfigurationRepository::class)]
#[CoversClass(MongoConnection::class)]
final class MongoRepositoryIntegrationTest extends TestCase
{
    private MongoConnection $connection;
    private MongoBranchRepository $branches;
    private MongoStoreConfigurationRepository $configs;

    protected function setUp(): void
    {
        if (!MongoConnection::isAvailable()) {
            self::markTestSkipped('Розширення PHP ext-mongodb не встановлено.');
        }

        $dsn = $_SERVER['MONGODB_URL'] ?? 'mongodb://127.0.0.1:27017';
        $database = 'yms_stores_test_'.getmypid();

        $this->connection = new MongoConnection((string) $dsn, $database);

        if (!$this->connection->ping()) {
            self::markTestSkipped('Сервер MongoDB недоступний за адресою '.$dsn);
        }

        $this->branches = new MongoBranchRepository($this->connection);
        $this->configs = new MongoStoreConfigurationRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && MongoConnection::isAvailable() && $this->connection->ping()) {
            $this->connection->command(['dropDatabase' => 1]);
        }
    }

    public function testIndexesAreCreated(): void
    {
        $created = (new MongoIndexInitializer($this->connection))->createAll();

        self::assertCount(5, $created);

        $indexes = $this->connection->command(['listIndexes' => MongoBranchRepository::COLLECTION]);
        $names = array_map(static fn (array $i): string => (string) $i['name'], $indexes);

        self::assertContains('externalId_unique', $names);
        self::assertContains('location_2dsphere', $names);
    }

    public function testBranchRoundTripPreservesAllFields(): void
    {
        $branch = BranchFactory::branch();
        $branch->rename('Сільпо Івасюка', new \DateTimeImmutable('2026-08-27T08:00:00+00:00'));
        $branch->setPhone('+380441234567', new \DateTimeImmutable('2026-08-27T08:00:00+00:00'));

        $this->branches->save($branch);
        $loaded = $this->branches->find($branch->id());

        self::assertInstanceOf(Branch::class, $loaded);
        self::assertSame('1998', $loaded->externalId());
        self::assertSame('Сільпо Івасюка', $loaded->displayName());
        self::assertSame('+380441234567', $loaded->phone());
        self::assertSame(YmsStatus::NotConfigured, $loaded->ymsStatus());
        self::assertEqualsWithDelta(50.52022, $loaded->mcpData()->location?->latitude, 0.000001);
        self::assertSame(
            $branch->syncedAt()->format(\DATE_ATOM),
            $loaded->syncedAt()->format(\DATE_ATOM),
        );
    }

    public function testIneligibilityReasonsSurviveRoundTrip(): void
    {
        $branch = BranchFactory::branch(['externalId' => 'delete_filia', 'city' => '']);
        $this->branches->save($branch);

        $loaded = $this->branches->find($branch->id());

        self::assertFalse($loaded?->isEligible());
        self::assertCount(2, $loaded?->ineligibilityReasons() ?? []);
    }

    public function testSearchAppliesFiltersAndPagination(): void
    {
        $this->branches->saveAll([
            BranchFactory::branch(),
            BranchFactory::branch([
                'branchId' => '1eda8887-bf7c-6f38-b0cb-9503162b5586',
                'externalId' => '2025',
                'city' => 'Львів',
                'address' => 'вул. Городоцька, 1',
            ]),
        ]);

        $kyiv = $this->branches->search(new BranchCriteria(cities: ['Київ'], perPage: 20));

        self::assertSame(1, $kyiv->total);
        self::assertSame('1998', $kyiv->items[0]->externalId());

        $byAddress = $this->branches->search(new BranchCriteria(query: 'Городоцька', perPage: 20));

        self::assertSame(1, $byAddress->total);
        self::assertSame('2025', $byAddress->items[0]->externalId());
    }

    public function testCitiesAggregationSkipsEmptyCity(): void
    {
        $this->branches->saveAll([
            BranchFactory::branch(),
            BranchFactory::branch([
                'branchId' => '1eda8887-bf7c-6f38-b0cb-9503162b5586',
                'externalId' => 'delete_x',
                'city' => '',
                'address' => '',
            ]),
        ]);

        $cities = $this->branches->cities(new BranchCriteria());

        self::assertSame([['city' => 'Київ', 'storeCount' => 1]], $cities);
    }

    public function testConfigurationVersioningReadsEffectiveVersion(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration(
            version: 1,
            effectiveFrom: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            maxWeight: 10.0,
        ));
        $this->configs->save(BranchFactory::completeConfiguration(
            version: 2,
            effectiveFrom: new \DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            maxWeight: 20.0,
        ));

        $current = $this->configs->findEffectiveAt(BranchFactory::KYIV_ID, new \DateTimeImmutable('2026-08-27T00:00:00+00:00'));
        $future = $this->configs->findEffectiveAt(BranchFactory::KYIV_ID, new \DateTimeImmutable('2026-09-15T00:00:00+00:00'));

        self::assertInstanceOf(StoreConfiguration::class, $current);
        self::assertSame(10.0, $current->maxVehicleWeightTons);
        self::assertSame(20.0, $future?->maxVehicleWeightTons);
        self::assertSame(3, $this->configs->nextVersion(BranchFactory::KYIV_ID));
    }

    public function testConfiguredStoreIdsReflectsCompleteness(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $at = new \DateTimeImmutable('2026-08-27T00:00:00+00:00');

        self::assertSame([BranchFactory::KYIV_ID], $this->configs->configuredStoreIds($at));
    }
}
