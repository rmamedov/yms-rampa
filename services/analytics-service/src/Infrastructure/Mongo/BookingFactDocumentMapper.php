<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;
use App\Domain\Fact\BookingFact;

/**
 * Мапер BookingFact ↔ документ MongoDB.
 *
 * Мапер чистий: працює з масивами і \DateTimeImmutable, тому покривається
 * тестами без встановленого ext-mongodb. Перетворення дат у BSON виконує
 * BsonCodec уже в репозиторії.
 */
final readonly class BookingFactDocumentMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toDocument(BookingFact $fact): array
    {
        return [
            '_id' => $fact->bookingId,
            'bookingId' => $fact->bookingId,
            'storeId' => $fact->storeId,
            'city' => $fact->city,
            'supplierId' => $fact->supplierId,
            'rampId' => $fact->rampId(),
            'slotStart' => $fact->slotStart,
            'slotEnd' => $fact->slotEnd,
            'type' => $fact->type->value,
            'status' => $fact->status()->value,
            'arrivedAt' => $fact->arrivedAt(),
            'unloadingStartedAt' => $fact->unloadingStartedAt(),
            'completedAt' => $fact->completedAt(),
            'cancelledAt' => $fact->cancelledAt(),
            'noShowAt' => $fact->noShowAt(),
            'rejectedAt' => $fact->rejectedAt(),
            'palletsCount' => $fact->palletsCount,
            'unloadedPalletsCount' => $fact->unloadedPalletsCount(),
            'partialUnload' => $fact->isPartialUnload(),
            'delayed' => $fact->isDelayed(),
            'delayReason' => $fact->delayReason(),
            'delayEta' => $fact->delayEta(),
            'rejectedReason' => $fact->rejectedReason()?->value,
            'rescheduleOf' => $fact->rescheduleOf,
            'createdAt' => $fact->createdAt,
            'updatedAt' => $fact->updatedAt(),
            'processedEventIds' => $fact->processedEventIds(),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public function fromDocument(array $document): BookingFact
    {
        $type = BookingType::from((string) ($document['type'] ?? BookingType::Scheduled->value));
        $status = BookingStatus::from((string) ($document['status'] ?? BookingStatus::Booked->value));

        $processed = $document['processedEventIds'] ?? [];
        if (!is_array($processed)) {
            $processed = [];
        }

        return BookingFact::restore(
            bookingId: (string) $document['bookingId'],
            storeId: (string) $document['storeId'],
            city: (string) $document['city'],
            supplierId: (string) $document['supplierId'],
            rampId: (string) $document['rampId'],
            slotStart: BsonCodec::requireDate($document['slotStart'] ?? null, 'slotStart'),
            slotEnd: BsonCodec::requireDate($document['slotEnd'] ?? null, 'slotEnd'),
            type: $type,
            status: $status,
            palletsCount: (int) ($document['palletsCount'] ?? 0),
            arrivedAt: BsonCodec::decodeDate($document['arrivedAt'] ?? null),
            unloadingStartedAt: BsonCodec::decodeDate($document['unloadingStartedAt'] ?? null),
            completedAt: BsonCodec::decodeDate($document['completedAt'] ?? null),
            cancelledAt: BsonCodec::decodeDate($document['cancelledAt'] ?? null),
            noShowAt: BsonCodec::decodeDate($document['noShowAt'] ?? null),
            rejectedAt: BsonCodec::decodeDate($document['rejectedAt'] ?? null),
            unloadedPalletsCount: isset($document['unloadedPalletsCount']) && $document['unloadedPalletsCount'] !== null
                ? (int) $document['unloadedPalletsCount']
                : null,
            partialUnload: (bool) ($document['partialUnload'] ?? false),
            delayed: (bool) ($document['delayed'] ?? false),
            delayReason: isset($document['delayReason']) && $document['delayReason'] !== null
                ? (string) $document['delayReason']
                : null,
            delayEta: BsonCodec::decodeDate($document['delayEta'] ?? null),
            rejectedReason: RejectionReason::fromCode(
                isset($document['rejectedReason']) && $document['rejectedReason'] !== null
                    ? (string) $document['rejectedReason']
                    : null,
            ),
            rescheduleOf: isset($document['rescheduleOf']) && $document['rescheduleOf'] !== null
                ? (string) $document['rescheduleOf']
                : null,
            createdAt: BsonCodec::decodeDate($document['createdAt'] ?? null),
            updatedAt: BsonCodec::decodeDate($document['updatedAt'] ?? null),
            processedEventIds: array_values(array_map(strval(...), $processed)),
        );
    }
}
