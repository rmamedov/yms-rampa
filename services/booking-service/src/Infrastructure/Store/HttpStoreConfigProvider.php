<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Domain\Booking\StoreSnapshot;
use App\Domain\Exception\UpstreamUnavailableException;
use App\Domain\Slot\CalendarException;
use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\StoreConfig;
use App\Domain\Slot\TimeInterval;
use App\Domain\Store\GeoPoint;
use App\Domain\Store\StoreConfigProvider;
use App\Domain\Store\StoreNotFoundException;
use App\Domain\Store\StorePolicy;
use App\Domain\Store\StoreSettings;
use InvalidArgumentException;
use TypeError;

/**
 * Реальний постачальник конфігурації: читає GET /internal/v1/stores/{id}/settings
 * store-service і мапить відповідь у доменні обʼєкти (SLOT-04).
 *
 * Що з відповіді сюди не потрапляє і чому:
 *   - configVersion / effectiveFrom — службові поля для інвалідації кешу,
 *     доменного сенсу для сітки не мають;
 *   - reservedSlotRules / slotBlocks — це накладання, їх читає
 *     HttpSlotOverlayProvider з ТОГО САМОГО тіла (кеш клієнта тримає
 *     один мережевий виклик на запит).
 *
 * Мережеві параметри движка (дедлайн змін, TTL холду, ліміт анти-сквотингу)
 * store-service не віддає — вони лишаються дефолтами StorePolicy, але код
 * читає їх з тіла, якщо колись зʼявляться.
 */
final readonly class HttpStoreConfigProvider implements StoreConfigProvider
{
    public function __construct(private StoreServiceClient $client)
    {
    }

    public function settingsFor(string $storeId): StoreSettings
    {
        $payload = $this->client->fetchStore($storeId);

        // 404 сусіда — STORE_NOT_FOUND або STORE_NOT_CONFIGURED; для бронювання
        // обидва означають «магазину немає», причина назовні не розкривається.
        if (null === $payload) {
            throw new StoreNotFoundException($storeId);
        }

        try {
            return self::map($storeId, $payload);
        } catch (InvalidArgumentException|TypeError $error) {
            // Тіло прийшло, але доменні інваріанти не збираються (напр. слот
            // 45 хв або виняток календаря без інтервалів). Це поламаний
            // контракт сусіда, а не помилка користувача — і точно не 500.
            throw UpstreamUnavailableException::badResponse('store-service', $error->getMessage(), $error);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function map(string $storeId, array $payload): StoreSettings
    {
        $windows = [];
        foreach ((array) ($payload['receivingWindows'] ?? []) as $window) {
            $window = (array) $window;
            $intervals = self::intervals($window['intervals'] ?? []);

            // День без інтервалів — це «прийому немає», а не помилка: саме так
            // store-service описує вихідний. Наш ReceivingWindow порожній
            // список не приймає, тож такі дні просто пропускаємо — слотів вони
            // однаково не дають.
            //
            // Без цього будь-яка філія з явно порожнім днем валила побудову
            // сітки: постачальник отримував 502 і не бачив слотів узагалі.
            if ([] === $intervals) {
                continue;
            }

            $windows[] = new ReceivingWindow(
                (int) ($window['dayOfWeek'] ?? 0),
                $intervals,
            );
        }

        $ramps = [];
        foreach ((array) ($payload['ramps'] ?? []) as $ramp) {
            $ramp = (array) $ramp;
            $ramps[] = new Ramp(
                rampId: (string) ($ramp['rampId'] ?? ''),
                name: (string) ($ramp['name'] ?? $ramp['rampId'] ?? ''),
                active: (bool) ($ramp['active'] ?? true),
                // Номер рампи потрібен контуру водія: на воротах написано «2»,
                // а не «ramp-2» (DRV-21).
                number: isset($ramp['number']) && is_numeric($ramp['number']) ? (int) $ramp['number'] : null,
            );
        }

        $exceptions = [];
        foreach ((array) ($payload['calendarExceptions'] ?? []) as $exception) {
            $exception = (array) $exception;
            $reason = (string) ($exception['reason'] ?? '');

            $exceptions[] = new CalendarException(
                date: (string) ($exception['date'] ?? ''),
                closed: (bool) ($exception['closed'] ?? false),
                intervals: self::intervals($exception['intervals'] ?? []),
                reason: '' === $reason ? null : $reason,
            );
        }

        $snapshot = (array) ($payload['snapshot'] ?? []);
        // Дефолти мережевого рівня (6.11) — щоб не дублювати числа у двох місцях.
        $defaults = new StorePolicy();

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
                editDeadlineHours: (int) ($payload['editDeadlineHours'] ?? $defaults->editDeadlineHours),
                noShowGraceMinutes: (int) ($payload['noShowGraceMinutes'] ?? $defaults->noShowGraceMinutes),
                holdTtlSeconds: (int) ($payload['holdTtlSeconds'] ?? $defaults->holdTtlSeconds),
                holdMaxMinutes: (int) ($payload['holdMaxMinutes'] ?? $defaults->holdMaxMinutes),
                maxActiveBookingsPerSupplier: (int) (
                    $payload['maxActiveBookingsPerSupplier'] ?? $defaults->maxActiveBookingsPerSupplier
                ),
            ),
            snapshot: new StoreSnapshot(
                externalId: (string) ($snapshot['externalId'] ?? $storeId),
                displayName: (string) ($snapshot['displayName'] ?? ''),
                city: (string) ($snapshot['city'] ?? ''),
                address: (string) ($snapshot['address'] ?? ''),
            ),
            // GRID-01, крок 2. Прихована від постачальників філія для контуру
            // постачальника не існує так само, як не-active: у store-service
            // каталог для постачальника вимагає ymsStatus=active І
            // visibleToSuppliers=true (SupplierCatalogService), тож бронювати
            // те, чого постачальник не бачить у списку, він теж не може.
            // Персонал мережі ознакою не обмежений — її читає лише SlotGridService.
            ymsActive: 'active' === (string) ($payload['ymsStatus'] ?? 'active')
                && (bool) ($payload['visibleToSuppliers'] ?? true),
            // Координати філії (DRV-21). Сусід віддає їх у тому ж блоці snapshot;
            // якщо їх немає або вони поламані — лишається null, і контур водія
            // відкриває навігатор за текстовою адресою.
            location: GeoPoint::tryFrom($snapshot['latitude'] ?? null, $snapshot['longitude'] ?? null),
        );
    }

    /**
     * @param mixed $raw список {from,to}
     *
     * @return list<TimeInterval>
     */
    private static function intervals(mixed $raw): array
    {
        $intervals = [];

        foreach ((array) $raw as $interval) {
            $interval = (array) $interval;
            $intervals[] = new TimeInterval((string) ($interval['from'] ?? ''), (string) ($interval['to'] ?? ''));
        }

        return $intervals;
    }
}
