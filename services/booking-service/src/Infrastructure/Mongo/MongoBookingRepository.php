<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Slot\SlotKey;
use App\Domain\Slot\StoreConfig;
use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Query;
use MongoDB\Driver\Session;

/**
 * Сховище бронювань у MongoDB (колекція `bookings`, схема 10.3.1).
 *
 * BOOK-07: вставка виконується як `findAndModify` з `upsert` і фільтром
 * на відсутність активного бронювання на ключі слота; цілісність гарантує
 * частковий унікальний індекс {storeId, rampId, slotStart} з умовою
 * status ∈ {booked, arrived, unloading} (DATA-12).
 *
 * BOOK-08: помилка дубліката E11000 перетворюється на SlotAlreadyBookedException
 * і далі — на HTTP 409 SLOT_ALREADY_BOOKED.
 */
final readonly class MongoBookingRepository implements BookingRepository
{
    public const string COLLECTION = 'bookings';

    public function __construct(
        private MongoConnection $connection,
        private MongoOutboxStore $outbox,
    ) {
    }

    public function insertIfSlotFree(Booking $booking, array $events): void
    {
        $this->connection->transactional(function (?Session $session) use ($booking, $events): void {
            $this->upsertIfSlotFree($booking, $session);
            $this->outbox->appendInSession($events, $session);
        });
    }

    public function save(Booking $booking, array $events): void
    {
        $this->connection->transactional(function (?Session $session) use ($booking, $events): void {
            $bulk = new BulkWrite();
            $bulk->update(
                ['_id' => $booking->id],
                ['$set' => BookingDocumentMapper::toDocument($booking)],
                ['upsert' => false],
            );

            try {
                $this->connection->manager()->executeBulkWrite(
                    $this->connection->namespace(self::COLLECTION),
                    $bulk,
                    null === $session ? [] : ['session' => $session],
                );
            } catch (BulkWriteException $error) {
                throw self::mapDuplicateKey($error, $booking->slotKey());
            }

            $this->outbox->appendInSession($events, $session);
        });
    }

    public function reschedule(Booking $newBooking, Booking $cancelledBooking, array $events): void
    {
        $this->connection->transactional(function (?Session $session) use ($newBooking, $cancelledBooking, $events): void {
            // EDIT-01: спершу вставка нового бронювання — якщо слот зайнято,
            // транзакція відкочується і старе бронювання лишається чинним.
            $this->upsertIfSlotFree($newBooking, $session);

            $bulk = new BulkWrite();
            $bulk->update(
                ['_id' => $cancelledBooking->id],
                ['$set' => BookingDocumentMapper::toDocument($cancelledBooking)],
                ['upsert' => false],
            );

            $this->connection->manager()->executeBulkWrite(
                $this->connection->namespace(self::COLLECTION),
                $bulk,
                null === $session ? [] : ['session' => $session],
            );

            $this->outbox->appendInSession($events, $session);
        });
    }

    public function find(string $bookingId): ?Booking
    {
        $documents = $this->query(['_id' => $bookingId], ['limit' => 1]);

        return $documents[0] ?? null;
    }

    public function findActiveBySlotKey(SlotKey $slotKey): ?Booking
    {
        $documents = $this->query([
            'storeId' => $slotKey->storeId,
            'rampId' => $slotKey->rampId,
            'slotStart' => self::date($slotKey->slotStart),
            'status' => ['$in' => BookingStatus::activeValues()],
        ], ['limit' => 1]);

        return $documents[0] ?? null;
    }

    public function activeSlotKeys(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $bookings = $this->query([
            'storeId' => $storeId,
            'status' => ['$in' => BookingStatus::activeValues()],
            'slotStart' => ['$gte' => self::date($from), '$lt' => self::date($to)],
        ]);

        return array_values(array_unique(array_map(
            static fn (Booking $booking) => $booking->slotKey()->toString(),
            $bookings,
        )));
    }

    public function countActiveFutureBySupplier(string $supplierId, DateTimeImmutable $now): int
    {
        $cursor = $this->connection->manager()->executeCommand(
            $this->connection->database(),
            new Command([
                'count' => self::COLLECTION,
                'query' => [
                    'supplierId' => $supplierId,
                    'type' => BookingType::Scheduled->value,
                    'status' => BookingStatus::Booked->value,
                    'slotStart' => ['$gt' => self::date($now)],
                ],
            ]),
        );

        $result = current($cursor->toArray());

        return null === $result ? 0 : (int) ($result->n ?? 0);
    }

    public function findOverlappingByPlate(
        string $supplierId,
        string $plateNumber,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $excludeBookingId = null,
    ): array {
        $filter = [
            'supplierId' => $supplierId,
            'vehicle.plateNumber' => $plateNumber,
            'status' => ['$in' => BookingStatus::activeValues()],
            'slotStart' => ['$lt' => self::date($to)],
            'slotEnd' => ['$gt' => self::date($from)],
        ];

        if (null !== $excludeBookingId) {
            $filter['_id'] = ['$ne' => $excludeBookingId];
        }

        return $this->query($filter);
    }

    public function findNoShowCandidates(DateTimeImmutable $slotEndBefore): array
    {
        return $this->query([
            'status' => BookingStatus::Booked->value,
            'slotEnd' => ['$lte' => self::date($slotEndBefore)],
        ], ['sort' => ['slotEnd' => 1]]);
    }

    public function findBySupplierAndLocalDate(string $supplierId, string $localDate): array
    {
        $tz = new DateTimeZone(StoreConfig::TIMEZONE);
        $start = new DateTimeImmutable($localDate.' 00:00:00', $tz);
        $end = $start->modify('+1 day');

        return $this->query([
            'supplierId' => $supplierId,
            'slotStart' => ['$gte' => self::date($start), '$lt' => self::date($end)],
        ], ['sort' => ['slotStart' => 1]]);
    }

    public function findByStoreAndRange(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->query([
            'storeId' => $storeId,
            'slotStart' => ['$gte' => self::date($from), '$lt' => self::date($to)],
        ], ['sort' => ['slotStart' => 1]]);
    }

    /**
     * BOOK-07: `findAndModify` з upsert і фільтром на активні статуси.
     * Якщо повернувся документ з іншим _id — слот уже зайнято.
     */
    private function upsertIfSlotFree(Booking $booking, ?Session $session): void
    {
        $document = BookingDocumentMapper::toDocument($booking);
        $slotKey = $booking->slotKey();

        // Поля рівності фільтра Mongo підставляє в новий документ сам,
        // тому в $setOnInsert їх бути не повинно.
        unset($document['storeId'], $document['rampId'], $document['slotStart']);

        $options = null === $session ? [] : ['session' => $session];

        try {
            $cursor = $this->connection->manager()->executeCommand(
                $this->connection->database(),
                new Command([
                    'findAndModify' => self::COLLECTION,
                    'query' => [
                        'storeId' => $slotKey->storeId,
                        'rampId' => $slotKey->rampId,
                        'slotStart' => self::date($slotKey->slotStart),
                        'status' => ['$in' => BookingStatus::activeValues()],
                    ],
                    'update' => ['$setOnInsert' => $document],
                    'upsert' => true,
                    'new' => true,
                ]),
                $options,
            );
        } catch (CommandException $error) {
            throw self::mapDuplicateKey($error, $slotKey);
        }

        $result = current($cursor->toArray());
        $stored = null === $result ? null : ($result->value ?? null);

        if (null !== $stored && (string) $stored->_id !== $booking->id) {
            throw new SlotAlreadyBookedException($slotKey);
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<Booking>
     */
    private function query(array $filter, array $options = []): array
    {
        $cursor = $this->connection->manager()->executeQuery(
            $this->connection->namespace(self::COLLECTION),
            new Query($filter, $options),
        );

        $bookings = [];

        foreach ($cursor as $document) {
            $bookings[] = BookingDocumentMapper::fromDocument(self::normalize($document));
        }

        return $bookings;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalize(mixed $document): array
    {
        $array = (array) $document;

        foreach ($array as $key => $value) {
            if (\is_object($value) && !$value instanceof UTCDateTime) {
                $array[$key] = self::normalize($value);
            } elseif (\is_array($value)) {
                $array[$key] = array_map(
                    static fn ($item) => \is_object($item) && !$item instanceof UTCDateTime ? self::normalize($item) : $item,
                    $value,
                );
            }
        }

        return $array;
    }

    private static function date(DateTimeImmutable $value): UTCDateTime
    {
        return new UTCDateTime($value->getTimestamp() * 1000);
    }

    private static function mapDuplicateKey(
        BulkWriteException|CommandException $error,
        SlotKey $slotKey,
    ): SlotAlreadyBookedException|BulkWriteException|CommandException {
        // BOOK-08: E11000 — порушення часткового унікального індексу DATA-12.
        if (11000 === $error->getCode() || str_contains($error->getMessage(), 'E11000')) {
            return new SlotAlreadyBookedException($slotKey, $error);
        }

        return $error;
    }
}
