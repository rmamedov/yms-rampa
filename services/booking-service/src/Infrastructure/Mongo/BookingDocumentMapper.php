<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Access\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\Cancellation;
use App\Domain\Booking\CancelledBy;
use App\Domain\Booking\DelayInfo;
use App\Domain\Booking\PartialUnload;
use App\Domain\Booking\PartialUnloadReason;
use App\Domain\Booking\Rejection;
use App\Domain\Booking\RejectionReason;
use App\Domain\Booking\StatusChange;
use App\Domain\Booking\StoreSnapshot;
use App\Domain\Booking\VehicleSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\UTCDateTime;

/**
 * Перетворення агрегату Booking у документ колекції `bookings` і назад
 * (схема 10.3.1). Дати зберігаються в UTC як BSON date.
 */
final readonly class BookingDocumentMapper
{
    /**
     * 4 — у записах statusHistory зʼявилися роль виконавця (`byRole`) і ознака
     * планового завдання (`bySystem`). Зміна сумісна вниз: документи версії 3
     * читаються як «роль невідома», міграція не потрібна.
     */
    public const int SCHEMA_VERSION = 4;

    /**
     * @return array<string, mixed>
     */
    public static function toDocument(Booking $booking): array
    {
        return [
            '_id' => $booking->id,
            'type' => $booking->type->value,
            'storeId' => $booking->storeId,
            'storeSnapshot' => $booking->storeSnapshot->toArray(),
            'rampId' => $booking->rampId(),
            'slotStart' => self::date($booking->slotStart),
            'slotEnd' => self::date($booking->slotEnd),
            'supplierId' => $booking->supplierId,
            'supplierNameSnapshot' => $booking->supplierNameSnapshot,
            'vehicle' => $booking->vehicle()->toArray(),
            'driverId' => $booking->driverId(),
            'orderId' => $booking->orderId(),
            'palletsCount' => $booking->palletsCount(),
            'status' => $booking->status()->value,
            'delayed' => [
                'flag' => $booking->delayed()->flag,
                'reason' => $booking->delayed()->reason,
                'eta' => self::dateOrNull($booking->delayed()->eta),
            ],
            'arrivedAt' => self::dateOrNull($booking->arrivedAt()),
            'unloadingStartedAt' => self::dateOrNull($booking->unloadingStartedAt()),
            'completedAt' => self::dateOrNull($booking->completedAt()),
            'cancelledAt' => self::dateOrNull($booking->cancelledAt()),
            'cancellation' => $booking->cancellation()?->toArray(),
            'rejectedAt' => null === $booking->rejection() ? null : [
                'at' => self::date($booking->rejection()->at),
                'by' => $booking->rejection()->by,
                'reason' => $booking->rejection()->reason->value,
                'comment' => $booking->rejection()->comment,
            ],
            'unloadedPalletsCount' => $booking->unloadedPalletsCount(),
            'partialUnload' => $booking->partialUnload()?->toArray(),
            'rescheduleOf' => $booking->rescheduleOf,
            'createdBy' => $booking->createdBy,
            'statusHistory' => array_map(
                static fn (StatusChange $change) => [
                    'from' => $change->from?->value,
                    'to' => $change->to->value,
                    'at' => self::date($change->at),
                    'by' => $change->by,
                    // DATA-14: роль і контур виконавця зберігаються разом із
                    // переходом — журнал має читатися без звернень до сусідів
                    // і не залежати від того, ким користувач став пізніше.
                    'byRole' => $change->byRole?->value,
                    'bySystem' => $change->bySystem,
                    'meta' => $change->meta,
                ],
                $booking->statusHistory(),
            ),
            'routeSheetId' => $booking->routeSheetId(),
            'rampReassigned' => $booking->rampReassigned(),
            'schemaVersion' => self::SCHEMA_VERSION,
            'createdAt' => self::date($booking->createdAt),
            'updatedAt' => self::date($booking->updatedAt()),
            'archivedAt' => null,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): Booking
    {
        $vehicle = (array) $document['vehicle'];
        $delayed = (array) ($document['delayed'] ?? []);
        $rejection = $document['rejectedAt'] ?? null;
        $cancellation = $document['cancellation'] ?? null;
        $partialUnload = $document['partialUnload'] ?? null;

        return Booking::reconstitute(
            id: (string) $document['_id'],
            type: BookingType::from((string) $document['type']),
            storeId: (string) $document['storeId'],
            storeSnapshot: self::snapshot((array) $document['storeSnapshot']),
            rampId: (string) $document['rampId'],
            slotStart: self::toDateTime($document['slotStart']),
            slotEnd: self::toDateTime($document['slotEnd']),
            supplierId: isset($document['supplierId']) ? (string) $document['supplierId'] : null,
            supplierNameSnapshot: (string) ($document['supplierNameSnapshot'] ?? ''),
            vehicle: new VehicleSnapshot(
                plateNumber: (string) $vehicle['plateNumber'],
                weightTons: (float) $vehicle['weightTons'],
                brand: isset($vehicle['brand']) ? (string) $vehicle['brand'] : null,
            ),
            driverId: isset($document['driverId']) ? (string) $document['driverId'] : null,
            orderId: isset($document['orderId']) ? (string) $document['orderId'] : null,
            palletsCount: (int) $document['palletsCount'],
            status: BookingStatus::from((string) $document['status']),
            rescheduleOf: isset($document['rescheduleOf']) ? (string) $document['rescheduleOf'] : null,
            createdBy: (string) $document['createdBy'],
            createdAt: self::toDateTime($document['createdAt']),
            statusHistory: array_map(
                static fn (array $change) => new StatusChange(
                    from: isset($change['from']) ? BookingStatus::from((string) $change['from']) : null,
                    to: BookingStatus::from((string) $change['to']),
                    at: self::toDateTime($change['at']),
                    by: (string) $change['by'],
                    meta: (array) ($change['meta'] ?? []),
                    // DATA-02: документи, записані до появи полів, читаються
                    // як «роль невідома» — журнал показує їх без колонки «Хто»,
                    // а не з вигаданим значенням.
                    byRole: isset($change['byRole']) && null !== $change['byRole']
                        ? Role::tryFrom((string) $change['byRole'])
                        : null,
                    bySystem: (bool) ($change['bySystem'] ?? false),
                ),
                array_map(static fn ($change) => (array) $change, (array) ($document['statusHistory'] ?? [])),
            ),
            delayed: new DelayInfo(
                flag: (bool) ($delayed['flag'] ?? false),
                reason: isset($delayed['reason']) ? (string) $delayed['reason'] : null,
                eta: isset($delayed['eta']) && null !== $delayed['eta'] ? self::toDateTime($delayed['eta']) : null,
            ),
            arrivedAt: self::toDateTimeOrNull($document['arrivedAt'] ?? null),
            unloadingStartedAt: self::toDateTimeOrNull($document['unloadingStartedAt'] ?? null),
            completedAt: self::toDateTimeOrNull($document['completedAt'] ?? null),
            cancelledAt: self::toDateTimeOrNull($document['cancelledAt'] ?? null),
            cancellation: null === $cancellation ? null : self::cancellation((array) $cancellation),
            rejection: null === $rejection ? null : self::rejection((array) $rejection),
            unloadedPalletsCount: isset($document['unloadedPalletsCount']) && null !== $document['unloadedPalletsCount']
                ? (int) $document['unloadedPalletsCount']
                : null,
            partialUnload: null === $partialUnload ? null : self::partialUnload((array) $partialUnload),
            routeSheetId: isset($document['routeSheetId']) ? (string) $document['routeSheetId'] : null,
            rampReassigned: (bool) ($document['rampReassigned'] ?? false),
            updatedAt: self::toDateTimeOrNull($document['updatedAt'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function snapshot(array $snapshot): StoreSnapshot
    {
        return new StoreSnapshot(
            externalId: (string) ($snapshot['externalId'] ?? ''),
            displayName: (string) ($snapshot['displayName'] ?? ''),
            city: (string) ($snapshot['city'] ?? ''),
            address: (string) ($snapshot['address'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function cancellation(array $data): Cancellation
    {
        return new Cancellation(
            by: CancelledBy::from((string) $data['by']),
            userId: (string) $data['userId'],
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function rejection(array $data): Rejection
    {
        return new Rejection(
            at: self::toDateTime($data['at']),
            by: (string) $data['by'],
            reason: RejectionReason::from((string) $data['reason']),
            comment: isset($data['comment']) ? (string) $data['comment'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function partialUnload(array $data): PartialUnload
    {
        return new PartialUnload(
            reason: PartialUnloadReason::from((string) $data['reason']),
            comment: isset($data['comment']) ? (string) $data['comment'] : null,
            flag: (bool) ($data['flag'] ?? true),
        );
    }

    private static function date(DateTimeImmutable $value): UTCDateTime
    {
        return new UTCDateTime($value->getTimestamp() * 1000);
    }

    private static function dateOrNull(?DateTimeImmutable $value): ?UTCDateTime
    {
        return null === $value ? null : self::date($value);
    }

    private static function toDateTime(mixed $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        if ($value instanceof UTCDateTime) {
            return $value->toDateTimeImmutable()->setTimezone($utc);
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($utc);
        }

        return new DateTimeImmutable((string) $value, $utc);
    }

    private static function toDateTimeOrNull(mixed $value): ?DateTimeImmutable
    {
        return null === $value ? null : self::toDateTime($value);
    }
}
