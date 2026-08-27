<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Domain\Booking\StoreSnapshot;
use App\Domain\Slot\CalendarException;
use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\StoreConfig;
use App\Domain\Slot\TimeInterval;
use App\Domain\Store\StoreConfigProvider;
use App\Domain\Store\StoreNotFoundException;
use App\Domain\Store\StorePolicy;
use App\Domain\Store\StoreSettings;

/**
 * Реальний постачальник конфігурації: читає store-service і мапить відповідь
 * у доменні обʼєкти. Кеш (Redis, TTL 60 с) інвалідовується подією
 * StoreConfigChanged — SLOT-04.
 */
final readonly class HttpStoreConfigProvider implements StoreConfigProvider
{
    public function __construct(private StoreServiceClient $client)
    {
    }

    public function settingsFor(string $storeId): StoreSettings
    {
        $payload = $this->client->fetchStore($storeId);

        if (null === $payload) {
            throw new StoreNotFoundException($storeId);
        }

        return self::map($storeId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function map(string $storeId, array $payload): StoreSettings
    {
        $windows = [];
        foreach ((array) ($payload['receivingWindows'] ?? []) as $window) {
            $window = (array) $window;
            $intervals = [];

            foreach ((array) ($window['intervals'] ?? []) as $interval) {
                $interval = (array) $interval;
                $intervals[] = new TimeInterval((string) $interval['from'], (string) $interval['to']);
            }

            $windows[] = new ReceivingWindow((int) $window['dayOfWeek'], $intervals);
        }

        $ramps = [];
        foreach ((array) ($payload['ramps'] ?? []) as $ramp) {
            $ramp = (array) $ramp;
            $ramps[] = new Ramp(
                rampId: (string) $ramp['rampId'],
                name: (string) ($ramp['name'] ?? $ramp['rampId']),
                active: (bool) ($ramp['active'] ?? true),
            );
        }

        $exceptions = [];
        foreach ((array) ($payload['calendarExceptions'] ?? []) as $exception) {
            $exception = (array) $exception;
            $intervals = [];

            foreach ((array) ($exception['intervals'] ?? []) as $interval) {
                $interval = (array) $interval;
                $intervals[] = new TimeInterval((string) $interval['from'], (string) $interval['to']);
            }

            $exceptions[] = new CalendarException(
                date: (string) $exception['date'],
                closed: (bool) ($exception['closed'] ?? false),
                intervals: $intervals,
                reason: isset($exception['reason']) ? (string) $exception['reason'] : null,
            );
        }

        $snapshot = (array) ($payload['snapshot'] ?? []);

        return new StoreSettings(
            config: new StoreConfig(
                storeId: $storeId,
                receivingWindows: $windows,
                slotSizeMinutes: (int) ($payload['slotSizeMinutes'] ?? 30),
                ramps: $ramps,
                maxVehicleWeightTons: (float) ($payload['maxVehicleWeightTons'] ?? 20.0),
                leadTimeMinutes: (int) ($payload['leadTimeMinutes'] ?? 60),
                bookingHorizonDays: (int) ($payload['bookingHorizonDays'] ?? 14),
                calendarExceptions: $exceptions,
            ),
            policy: new StorePolicy(
                editDeadlineHours: (int) ($payload['editDeadlineHours'] ?? 2),
                noShowGraceMinutes: (int) ($payload['noShowGraceMinutes'] ?? 30),
                holdTtlSeconds: (int) ($payload['holdTtlSeconds'] ?? 300),
                holdMaxMinutes: (int) ($payload['holdMaxMinutes'] ?? 15),
                maxActiveBookingsPerSupplier: (int) ($payload['maxActiveBookingsPerSupplier'] ?? 50),
            ),
            snapshot: new StoreSnapshot(
                externalId: (string) ($snapshot['externalId'] ?? $storeId),
                displayName: (string) ($snapshot['displayName'] ?? ''),
                city: (string) ($snapshot['city'] ?? ''),
                address: (string) ($snapshot['address'] ?? ''),
            ),
            ymsActive: 'active' === (string) ($payload['ymsStatus'] ?? 'active'),
        );
    }
}
