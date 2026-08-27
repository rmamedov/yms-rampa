<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\NotificationTemplate;
use App\Infrastructure\Mongo\MongoConnectionFactory;
use App\Infrastructure\Mongo\MongoNotificationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Інтеграційні перевірки сховища на реальній MongoDB.
 *
 * Тести пропускаються, якщо немає розширення ext-mongodb або сервер
 * недоступний — на машині розробника без MongoDB вони не падають.
 */
#[Group('integration')]
#[CoversClass(MongoNotificationRepository::class)]
#[CoversClass(MongoConnectionFactory::class)]
final class MongoNotificationRepositoryTest extends TestCase
{
    private ?MongoNotificationRepository $repository = null;

    protected function setUp(): void
    {
        if (!MongoConnectionFactory::isAvailable()) {
            self::markTestSkipped('Немає розширення ext-mongodb або бібліотеки mongodb/mongodb.');
        }

        $connection = new MongoConnectionFactory(
            $_SERVER['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017',
            'yms_notification_test',
        );

        try {
            $connection->collection(MongoNotificationRepository::COLLECTION)->deleteMany([]);
        } catch (\Throwable $e) {
            self::markTestSkipped('Сервер MongoDB недоступний: '.$e->getMessage());
        }

        $this->repository = new MongoNotificationRepository($connection);
    }

    public function testNotificationRoundTrip(): void
    {
        $repository = $this->repository;
        self::assertNotNull($repository);

        $notification = Notification::queue(
            id: $repository->nextIdentity(),
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            template: NotificationTemplate::BookingConfirmed,
            payload: ['date' => '05.09.2026', 'time' => '14:30'],
            now: new \DateTimeImmutable('2026-09-04 09:00:00', new \DateTimeZone('UTC')),
            correlationId: 'bkg-1',
        );
        $repository->save($notification);

        $loaded = $repository->find($notification->id());

        self::assertNotNull($loaded);
        self::assertSame(NotificationStatus::Queued, $loaded->status());
        self::assertSame('+380671234567', $loaded->recipient());
        self::assertSame('bkg-1', $loaded->correlationId());
        self::assertSame('05.09.2026', $loaded->payload()['date']);
    }

    public function testFindDueRespectsNextAttemptAt(): void
    {
        $repository = $this->repository;
        self::assertNotNull($repository);

        $now = new \DateTimeImmutable('2026-09-04 09:00:00', new \DateTimeZone('UTC'));
        $notification = Notification::queue(
            id: $repository->nextIdentity(),
            channel: NotificationChannel::Email,
            recipient: 'supplier@example.com',
            template: NotificationTemplate::BookingRejected,
            payload: [],
            now: $now,
        );
        $notification->registerFailedAttempt('збій', $now, $now->modify('+60 seconds'));
        $repository->save($notification);

        self::assertSame([], $repository->findDue($now));
        self::assertCount(1, $repository->findDue($now->modify('+61 seconds')));
    }
}
