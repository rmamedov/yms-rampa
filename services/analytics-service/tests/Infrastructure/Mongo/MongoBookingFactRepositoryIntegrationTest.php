<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Booking\BookingStatus;
use App\Domain\Projection\DomainEventName;
use App\Domain\Projection\EventProjector;
use App\Domain\Projection\ProjectionOutcome;
use App\Infrastructure\Mongo\MongoBookingFactRepository;
use App\Infrastructure\Mongo\MongoConnection;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Інтеграційні тести проти реальної MongoDB.
 *
 * На машині без розширення ext-mongodb або без піднятого сервера тест
 * робить markTestSkipped і НЕ падає: юніт-тести системи працюють
 * на InMemory-реалізаціях.
 *
 * Запуск лише цієї групи: vendor/bin/phpunit --group integration
 */
#[Group('integration')]
#[CoversClass(MongoBookingFactRepository::class)]
#[CoversClass(MongoConnection::class)]
final class MongoBookingFactRepositoryIntegrationTest extends TestCase
{
    private MongoConnection $connection;
    private MongoBookingFactRepository $repository;

    protected function setUp(): void
    {
        if (!MongoConnection::isDriverAvailable()) {
            self::markTestSkipped('Розширення PHP ext-mongodb не встановлено.');
        }

        $this->connection = new MongoConnection(
            dsn: (string) ($_SERVER['MONGODB_URL'] ?? 'mongodb://127.0.0.1:27017'),
            database: (string) ($_SERVER['MONGODB_DB'] ?? 'yms_analytics_test'),
        );

        try {
            $this->connection->manager()->executeCommand(
                $this->connection->database(),
                new \MongoDB\Driver\Command(['ping' => 1]),
            );
        } catch (\Throwable $exception) {
            self::markTestSkipped('MongoDB недоступна: ' . $exception->getMessage());
        }

        $this->repository = new MongoBookingFactRepository($this->connection);
        $this->dropCollection();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && MongoConnection::isDriverAvailable()) {
            $this->dropCollection();
        }
    }

    #[Test]
    public function savesAndReadsBackBookingFact(): void
    {
        $fact = Fixtures::booking(bookingId: 'mongo-1', status: BookingStatus::Completed, arrivedAt: '2026-03-16 07:50:00');
        $this->repository->save($fact);

        $restored = $this->repository->findByBookingId('mongo-1');

        self::assertNotNull($restored);
        self::assertSame(BookingStatus::Completed, $restored->status());
        self::assertSame('2026-03-16 07:50:00', $restored->arrivedAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function projectorRemainsIdempotentOnRealStorage(): void
    {
        $projector = new EventProjector($this->repository);
        $event = Fixtures::event(
            DomainEventName::BookingCreated,
            Fixtures::bookingCreatedPayload(['bookingId' => 'mongo-2']),
            eventId: 'evt-mongo-created',
        );

        self::assertSame(ProjectionOutcome::Applied, $projector->project($event)->outcome);
        self::assertSame(ProjectionOutcome::Duplicate, $projector->project($event)->outcome);
        self::assertSame(1, $this->repository->countAll());
    }

    #[Test]
    public function appliesDashboardFiltersInDatabaseQuery(): void
    {
        $this->repository->save(Fixtures::booking(bookingId: 'in', city: 'Київ', slotStart: '2026-03-16 08:00:00', slotEnd: '2026-03-16 08:30:00'));
        $this->repository->save(Fixtures::booking(bookingId: 'out', city: 'Львів', slotStart: '2026-03-16 08:00:00', slotEnd: '2026-03-16 08:30:00'));

        $found = $this->repository->findByQuery(new AnalyticsQuery(
            from: Fixtures::utc('2026-03-16 00:00:00'),
            to: Fixtures::utc('2026-03-17 00:00:00'),
            cities: ['Київ'],
        ));

        self::assertCount(1, $found);
        self::assertSame('in', $found[0]->bookingId);
    }

    private function dropCollection(): void
    {
        try {
            $this->connection->manager()->executeCommand(
                $this->connection->database(),
                new \MongoDB\Driver\Command(['drop' => MongoBookingFactRepository::COLLECTION]),
            );
        } catch (\Throwable) {
            // колекції ще немає — нормально
        }
    }
}
