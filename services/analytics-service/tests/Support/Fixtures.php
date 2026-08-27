<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;
use App\Domain\Fact\BookingFact;
use App\Domain\Projection\DomainEvent;
use App\Domain\Projection\DomainEventName;
use App\Domain\Slot\SlotFact;
use App\Domain\Slot\SlotState;

/**
 * Побудова еталонних наборів даних для тестів KPI.
 */
final class Fixtures
{
    public static function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    /**
     * @param list<string> $processedEventIds
     */
    public static function booking(
        string $bookingId = 'b1',
        string $storeId = 'store-1',
        string $city = 'Київ',
        string $supplierId = 'sup-1',
        string $rampId = 'ramp-1',
        string $slotStart = '2026-03-16 08:00:00',
        string $slotEnd = '2026-03-16 08:30:00',
        BookingType $type = BookingType::Scheduled,
        BookingStatus $status = BookingStatus::Completed,
        int $palletsCount = 10,
        ?string $arrivedAt = null,
        ?string $unloadingStartedAt = null,
        ?string $completedAt = null,
        ?int $unloadedPalletsCount = null,
        bool $partialUnload = false,
        bool $delayed = false,
        ?string $delayReason = null,
        ?RejectionReason $rejectedReason = null,
        ?string $updatedAt = null,
        array $processedEventIds = [],
    ): BookingFact {
        return BookingFact::restore(
            bookingId: $bookingId,
            storeId: $storeId,
            city: $city,
            supplierId: $supplierId,
            rampId: $rampId,
            slotStart: self::utc($slotStart),
            slotEnd: self::utc($slotEnd),
            type: $type,
            status: $status,
            palletsCount: $palletsCount,
            arrivedAt: $arrivedAt === null ? null : self::utc($arrivedAt),
            unloadingStartedAt: $unloadingStartedAt === null ? null : self::utc($unloadingStartedAt),
            completedAt: $completedAt === null ? null : self::utc($completedAt),
            unloadedPalletsCount: $unloadedPalletsCount,
            partialUnload: $partialUnload,
            delayed: $delayed,
            delayReason: $delayReason,
            rejectedReason: $rejectedReason,
            updatedAt: $updatedAt === null ? null : self::utc($updatedAt),
            processedEventIds: $processedEventIds,
        );
    }

    public static function slot(
        string $slotId,
        SlotState $state,
        string $start,
        string $end,
        string $storeId = 'store-1',
        string $city = 'Київ',
        string $rampId = 'ramp-1',
    ): SlotFact {
        return new SlotFact(
            slotId: $slotId,
            storeId: $storeId,
            city: $city,
            rampId: $rampId,
            start: self::utc($start),
            end: self::utc($end),
            state: $state,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function event(
        DomainEventName $name,
        array $payload,
        string $eventId = 'evt-1',
        string $occurredAt = '2026-03-16 08:00:00',
    ): DomainEvent {
        return new DomainEvent(
            eventId: $eventId,
            name: $name,
            occurredAt: self::utc($occurredAt),
            payload: $payload,
        );
    }

    /**
     * Типовий payload події BookingCreated.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function bookingCreatedPayload(array $overrides = []): array
    {
        return $overrides + [
            'bookingId' => 'b1',
            'storeId' => 'store-1',
            'city' => 'Київ',
            'supplierId' => 'sup-1',
            'rampId' => 'ramp-1',
            'slotStart' => '2026-03-16T08:00:00+00:00',
            'slotEnd' => '2026-03-16T08:30:00+00:00',
            'type' => 'scheduled',
            'palletsCount' => 12,
        ];
    }
}
