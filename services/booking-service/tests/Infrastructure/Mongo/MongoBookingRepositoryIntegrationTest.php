<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Infrastructure\Mongo\BookingDocumentMapper;
use App\Infrastructure\Mongo\MongoBookingRepository;
use App\Infrastructure\Mongo\MongoConnection;
use App\Infrastructure\Mongo\MongoIndexInstaller;
use App\Infrastructure\Mongo\MongoOutboxStore;
use App\Tests\Support\BookingFactory;
use App\Tests\Support\Scenario;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Інтеграційні перевірки проти реальної MongoDB: атомарна вставка BOOK-07
 * і частковий унікальний індекс DATA-12.
 *
 * Тест свідомо не є частиною звичайного прогону: без розширення `mongodb`
 * або без піднятого сервера він робить markTestSkipped, а не падає.
 * Запуск: vendor/bin/phpunit --group integration
 */
#[Group('integration')]
final class MongoBookingRepositoryIntegrationTest extends TestCase
{
    private const string DATABASE = 'yms_booking_service_test';

    private ?MongoConnection $connection = null;

    protected function setUp(): void
    {
        if (!\extension_loaded('mongodb')) {
            self::markTestSkipped('PHP-розширення mongodb не встановлено');
        }

        $uri = getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017';

        try {
            $manager = new Manager($uri, ['serverSelectionTimeoutMS' => 750]);
            $manager->executeCommand('admin', new Command(['ping' => 1]));
        } catch (Throwable $error) {
            self::markTestSkipped('Сервер MongoDB недоступний: '.$error->getMessage());
        }

        $this->connection = new MongoConnection($manager, self::DATABASE);
        $this->dropDatabase();
        (new MongoIndexInstaller($this->connection))->install();
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            $this->dropDatabase();
        }
    }

    /** BOOK-07/BOOK-08: другий запис на той самий ключ слота відхиляється. */
    public function testUniquePartialIndexPreventsDoubleBooking(): void
    {
        $repository = $this->repository();

        $first = BookingFactory::scheduled(id: 'bk-mongo-a');
        $second = BookingFactory::scheduled(id: 'bk-mongo-b', supplierId: Scenario::OTHER_SUPPLIER_ID);

        $repository->insertIfSlotFree($first, [$first->bookingCreatedEvent($first->createdAt)]);

        $this->expectException(SlotAlreadyBookedException::class);
        $repository->insertIfSlotFree($second, [$second->bookingCreatedEvent($second->createdAt)]);
    }

    /** BOOK-07: скасовані бронювання індексом не блокуються. */
    public function testCancelledBookingDoesNotBlockSlot(): void
    {
        $repository = $this->repository();

        $first = BookingFactory::scheduled(id: 'bk-mongo-c');
        $repository->insertIfSlotFree($first, []);

        $cancelEvent = $first->cancel(
            new \App\Domain\Access\Actor('su-1', \App\Domain\Access\Role::StoreManager, storeId: Scenario::STORE_ID),
            $first->createdAt,
            2,
        );
        $repository->save($first, [$cancelEvent]);

        $second = BookingFactory::scheduled(id: 'bk-mongo-d');
        $repository->insertIfSlotFree($second, []);

        self::assertSame('bk-mongo-d', $repository->findActiveBySlotKey($second->slotKey())?->id);
    }

    /** DATA-16: подія лягає в outbox разом із документом бронювання. */
    public function testOutboxRecordIsWrittenWithBooking(): void
    {
        $repository = $this->repository();
        $booking = BookingFactory::scheduled(id: 'bk-mongo-e');

        $repository->insertIfSlotFree($booking, [$booking->bookingCreatedEvent($booking->createdAt)]);

        $pending = (new MongoOutboxStore($this->connection()))->pending();

        self::assertCount(1, $pending);
        self::assertSame('BookingCreated', $pending[0]->event->type->value);
    }

    /** Документ читається назад у той самий агрегат (схема 10.3.1). */
    public function testDocumentRoundTripPreservesAggregate(): void
    {
        $repository = $this->repository();
        $booking = BookingFactory::scheduled(id: 'bk-mongo-f', driverId: 'du-9');

        $repository->insertIfSlotFree($booking, []);
        $loaded = $repository->find('bk-mongo-f');

        self::assertNotNull($loaded);
        self::assertSame($booking->palletsCount(), $loaded->palletsCount());
        self::assertSame($booking->vehicle()->plateNumber, $loaded->vehicle()->plateNumber);
        self::assertSame($booking->slotStart->getTimestamp(), $loaded->slotStart->getTimestamp());
        self::assertSame('du-9', $loaded->driverId());
        self::assertCount(1, $loaded->statusHistory());
    }

    /** Мапер працює без сервера: документ ↔ агрегат. */
    public function testMapperProducesCanonicalSchemaVersion(): void
    {
        $document = BookingDocumentMapper::toDocument(BookingFactory::scheduled(id: 'bk-mongo-g'));

        self::assertSame(3, $document['schemaVersion']);
        self::assertSame('scheduled', $document['type']);
        self::assertArrayHasKey('storeSnapshot', $document);
        self::assertArrayHasKey('statusHistory', $document);
    }

    private function repository(): MongoBookingRepository
    {
        $connection = $this->connection();

        return new MongoBookingRepository($connection, new MongoOutboxStore($connection));
    }

    private function connection(): MongoConnection
    {
        \assert(null !== $this->connection);

        return $this->connection;
    }

    private function dropDatabase(): void
    {
        try {
            $this->connection()->manager()->executeCommand(self::DATABASE, new Command(['dropDatabase' => 1]));
        } catch (Throwable) {
            // База могла ще не існувати — це не помилка.
        }
    }
}
