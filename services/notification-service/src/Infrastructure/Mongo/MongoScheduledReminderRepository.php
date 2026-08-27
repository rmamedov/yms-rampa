<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Reminder\ReminderStatus;
use App\Domain\Reminder\ScheduledReminder;
use App\Domain\Reminder\ScheduledReminderRepository;

/**
 * Реалізація сховища запланованих нагадувань на MongoDB (NOT-06).
 *
 * Колекція `scheduled_reminders`.
 */
final class MongoScheduledReminderRepository implements ScheduledReminderRepository
{
    public const string COLLECTION = 'scheduled_reminders';

    public function __construct(
        private readonly MongoConnectionFactory $connection,
    ) {
    }

    public function save(ScheduledReminder $reminder): void
    {
        $this->collection()->replaceOne(
            ['_id' => $reminder->id()],
            [
                '_id' => $reminder->id(),
                'bookingId' => $reminder->bookingId(),
                'template' => $reminder->template()->value,
                'channel' => $reminder->channel()->value,
                'recipient' => $reminder->recipient(),
                'recipientId' => $reminder->recipientId(),
                'payload' => $reminder->payload(),
                'sendAt' => $this->toBsonDate($reminder->sendAtUtc()),
                'status' => $reminder->status()->value,
            ],
            ['upsert' => true],
        );
    }

    public function findDue(\DateTimeImmutable $now, int $limit = 100): array
    {
        $cursor = $this->collection()->find(
            [
                'status' => ReminderStatus::Scheduled->value,
                'sendAt' => ['$lte' => $this->toBsonDate($now)],
            ],
            ['limit' => $limit, 'sort' => ['sendAt' => 1]],
        );

        return $this->hydrateAll($cursor);
    }

    public function findByBookingId(string $bookingId): array
    {
        return $this->hydrateAll($this->collection()->find(['bookingId' => $bookingId]));
    }

    public function nextIdentity(): string
    {
        return bin2hex(random_bytes(12));
    }

    public function ensureIndexes(): void
    {
        $this->collection()->createIndex(['status' => 1, 'sendAt' => 1]);
        $this->collection()->createIndex(['bookingId' => 1]);
    }

    /**
     * @param iterable<array<string, mixed>> $cursor
     *
     * @return list<ScheduledReminder>
     */
    private function hydrateAll(iterable $cursor): array
    {
        $result = [];
        foreach ($cursor as $document) {
            $result[] = $this->toDomain($document);
        }

        return $result;
    }

    /** @param array<string, mixed> $document */
    private function toDomain(array $document): ScheduledReminder
    {
        /** @var array<string, scalar|\Stringable|null> $payload */
        $payload = \is_array($document['payload'] ?? null) ? $document['payload'] : [];

        return new ScheduledReminder(
            id: (string) $document['_id'],
            bookingId: (string) $document['bookingId'],
            template: NotificationTemplate::from((string) $document['template']),
            channel: NotificationChannel::from((string) $document['channel']),
            recipient: (string) $document['recipient'],
            recipientId: isset($document['recipientId']) ? (string) $document['recipientId'] : null,
            payload: $payload,
            sendAtUtc: $this->toPhpDate($document['sendAt'] ?? null) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            status: ReminderStatus::from((string) $document['status']),
        );
    }

    /** @return \MongoDB\Collection */
    private function collection(): object
    {
        return $this->connection->collection(self::COLLECTION);
    }

    private function toBsonDate(\DateTimeImmutable $date): object
    {
        /** @var class-string $utcDateTime */
        $utcDateTime = 'MongoDB\BSON\UTCDateTime';

        return new $utcDateTime((int) $date->format('Uv'));
    }

    private function toPhpDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return \DateTimeImmutable::createFromMutable($value->toDateTime())
                ->setTimezone(new \DateTimeZone('UTC'));
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }

        return null;
    }
}
