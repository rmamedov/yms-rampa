<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationRepository;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\NotificationTemplate;

/**
 * Реалізація сховища сповіщень на MongoDB.
 *
 * Колекція `notifications`. Дати зберігаються в UTC (UTCDateTime).
 * Клас не використовується юніт-тестами — вони працюють на
 * InMemoryNotificationRepository і не потребують ані сервера, ані
 * розширення ext-mongodb.
 */
final class MongoNotificationRepository implements NotificationRepository
{
    public const string COLLECTION = 'notifications';

    public function __construct(
        private readonly MongoConnectionFactory $connection,
    ) {
    }

    public function save(Notification $notification): void
    {
        $this->collection()->replaceOne(
            ['_id' => $notification->id()],
            $this->toDocument($notification),
            ['upsert' => true],
        );
    }

    public function find(string $id): ?Notification
    {
        /** @var array<string, mixed>|null $document */
        $document = $this->collection()->findOne(['_id' => $id]);

        return null === $document ? null : $this->toDomain($document);
    }

    public function findDue(\DateTimeImmutable $now, int $limit = 100): array
    {
        $cursor = $this->collection()->find(
            [
                'status' => NotificationStatus::Queued->value,
                'nextAttemptAt' => ['$lte' => $this->toBsonDate($now)],
            ],
            ['limit' => $limit, 'sort' => ['nextAttemptAt' => 1]],
        );

        $result = [];
        foreach ($cursor as $document) {
            /** @var array<string, mixed> $document */
            $result[] = $this->toDomain($document);
        }

        return $result;
    }

    public function findByCorrelationId(string $correlationId): array
    {
        $cursor = $this->collection()->find(['correlationId' => $correlationId], ['sort' => ['createdAt' => 1]]);

        $result = [];
        foreach ($cursor as $document) {
            /** @var array<string, mixed> $document */
            $result[] = $this->toDomain($document);
        }

        return $result;
    }

    public function nextIdentity(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * Індекси, потрібні для роботи черги. Викликається консольною командою
     * ініціалізації сховища.
     */
    public function ensureIndexes(): void
    {
        $this->collection()->createIndex(['status' => 1, 'nextAttemptAt' => 1]);
        $this->collection()->createIndex(['correlationId' => 1]);
        $this->collection()->createIndex(['createdAt' => -1]);
    }

    /** @return \MongoDB\Collection */
    private function collection(): object
    {
        return $this->connection->collection(self::COLLECTION);
    }

    /** @return array<string, mixed> */
    private function toDocument(Notification $notification): array
    {
        return [
            '_id' => $notification->id(),
            'channel' => $notification->channel()->value,
            'recipient' => $notification->recipient(),
            'template' => $notification->template()->value,
            'payload' => $notification->payload(),
            'status' => $notification->status()->value,
            'attempts' => $notification->attempts(),
            'createdAt' => $this->toBsonDate($notification->createdAt()),
            'nextAttemptAt' => $this->toBsonDate($notification->nextAttemptAt()),
            'lastAttemptAt' => $this->toBsonDate($notification->lastAttemptAt()),
            'sentAt' => $this->toBsonDate($notification->sentAt()),
            'error' => $notification->error(),
            'providerMessageId' => $notification->providerMessageId(),
            'correlationId' => $notification->correlationId(),
            'recipientId' => $notification->recipientId(),
            'fallbackRecipient' => $notification->fallbackRecipient(),
            'fallbackSpawned' => $notification->fallbackSpawned(),
        ];
    }

    /** @param array<string, mixed> $document */
    private function toDomain(array $document): Notification
    {
        /** @var array<string, scalar|\Stringable|null> $payload */
        $payload = \is_array($document['payload'] ?? null) ? $document['payload'] : [];

        return Notification::restore(
            id: (string) $document['_id'],
            channel: NotificationChannel::from((string) $document['channel']),
            recipient: (string) $document['recipient'],
            template: NotificationTemplate::from((string) $document['template']),
            payload: $payload,
            createdAt: $this->toPhpDate($document['createdAt'] ?? null) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            status: NotificationStatus::from((string) $document['status']),
            attempts: (int) ($document['attempts'] ?? 0),
            nextAttemptAt: $this->toPhpDate($document['nextAttemptAt'] ?? null),
            lastAttemptAt: $this->toPhpDate($document['lastAttemptAt'] ?? null),
            sentAt: $this->toPhpDate($document['sentAt'] ?? null),
            error: isset($document['error']) ? (string) $document['error'] : null,
            providerMessageId: isset($document['providerMessageId']) ? (string) $document['providerMessageId'] : null,
            correlationId: isset($document['correlationId']) ? (string) $document['correlationId'] : null,
            recipientId: isset($document['recipientId']) ? (string) $document['recipientId'] : null,
            fallbackRecipient: isset($document['fallbackRecipient']) ? (string) $document['fallbackRecipient'] : null,
            fallbackSpawned: (bool) ($document['fallbackSpawned'] ?? false),
        );
    }

    private function toBsonDate(?\DateTimeImmutable $date): ?object
    {
        if (null === $date) {
            return null;
        }

        /** @var class-string $utcDateTime */
        $utcDateTime = 'MongoDB\BSON\UTCDateTime';

        return new $utcDateTime((int) $date->format('Uv'));
    }

    private function toPhpDate(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
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
