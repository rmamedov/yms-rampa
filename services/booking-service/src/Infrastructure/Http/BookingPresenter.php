<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Booking\Booking;
use App\Domain\Booking\StatusChange;
use App\Domain\Driver\DriverInfo;
use App\Domain\Slot\StoreConfig;
use DateTimeZone;

/**
 * Представлення бронювання у відповіді API. Дати — UTC ISO 8601,
 * додатково віддається локальний час магазину для відображення.
 */
final readonly class BookingPresenter
{
    /**
     * @param DriverInfo|null $driver знімок профілю призначеного водія, якщо
     *                                контур має його чим наповнити. Поле
     *                                `driverId` лишається на місці за будь-яких
     *                                умов: воно — частина документа бронювання,
     *                                тоді як `driver` — довідкове збагачення,
     *                                якого може не бути (водія не призначено,
     *                                профіль видалено, сусід не відповів)
     *
     * @return array<string, mixed>
     */
    public static function toArray(Booking $booking, ?DriverInfo $driver = null): array
    {
        $tz = new DateTimeZone(StoreConfig::TIMEZONE);

        return [
            'id' => $booking->id,
            'type' => $booking->type->value,
            'status' => $booking->status()->value,
            'storeId' => $booking->storeId,
            'store' => $booking->storeSnapshot->toArray(),
            'rampId' => $booking->rampId(),
            'slotStart' => $booking->slotStart->format('Y-m-d\TH:i:s\Z'),
            'slotEnd' => $booking->slotEnd->format('Y-m-d\TH:i:s\Z'),
            'localDate' => $booking->localDate(),
            'localTime' => $booking->slotStart->setTimezone($tz)->format('H:i'),
            'supplierId' => $booking->supplierId,
            'supplierName' => $booking->supplierNameSnapshot,
            'vehicle' => $booking->vehicle()->toArray(),
            'driverId' => $booking->driverId(),
            'driver' => $driver?->toArray(),
            'orderId' => $booking->orderId(),
            'palletsCount' => $booking->palletsCount(),
            'delayed' => $booking->delayed()->toArray(),
            'arrivedAt' => $booking->arrivedAt()?->format('Y-m-d\TH:i:s\Z'),
            // Позначка запізнення (розділ 8): прибуття зафіксовано після кінця
            // слоту. Похідне від arrivedAt і slotEnd — див. Booking::arrivedLate().
            'arrivedLate' => $booking->arrivedLate(),
            'unloadingStartedAt' => $booking->unloadingStartedAt()?->format('Y-m-d\TH:i:s\Z'),
            'completedAt' => $booking->completedAt()?->format('Y-m-d\TH:i:s\Z'),
            'cancelledAt' => $booking->cancelledAt()?->format('Y-m-d\TH:i:s\Z'),
            'cancellation' => $booking->cancellation()?->toArray(),
            'rejectedAt' => $booking->rejection()?->toArray(),
            'unloadedPalletsCount' => $booking->unloadedPalletsCount(),
            'partialUnload' => $booking->partialUnload()?->toArray(),
            'rescheduleOf' => $booking->rescheduleOf,
            'routeSheetId' => $booking->routeSheetId(),
            'createdBy' => $booking->createdBy,
            'createdAt' => $booking->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updatedAt' => $booking->updatedAt()->format('Y-m-d\TH:i:s\Z'),
            'statusHistory' => array_map(
                static fn (StatusChange $change) => $change->toArray(),
                $booking->statusHistory(),
            ),
        ];
    }
}
