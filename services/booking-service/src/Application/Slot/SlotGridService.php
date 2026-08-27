<?php

declare(strict_types=1);

namespace App\Application\Slot;

use App\Domain\Access\Actor;
use App\Domain\Booking\BookingRepository;
use App\Domain\Hold\SlotHoldStore;
use App\Domain\Slot\Slot;
use App\Domain\Slot\SlotGrid;
use App\Domain\Slot\SlotGridGenerator;
use App\Domain\Slot\SlotKey;
use App\Domain\Slot\SlotOverlayProvider;
use App\Domain\Slot\SlotOverlays;
use App\Domain\Slot\StoreConfig;
use App\Domain\Store\StoreConfigProvider;
use App\Domain\Store\StoreNotFoundException;
use App\Domain\Store\StoreSettings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Складання сітки слотів на дату (GRID-01): конфігурація магазину →
 * блокування → резерви → бронювання → холди → минуле і lead time.
 *
 * Сам алгоритм живе в доменному SlotGridGenerator; цей сервіс лише
 * збирає для нього матеріалізовані факти зі сховищ.
 */
final readonly class SlotGridService
{
    public function __construct(
        private StoreConfigProvider $storeConfigProvider,
        private SlotOverlayProvider $overlayProvider,
        private BookingRepository $bookings,
        private SlotHoldStore $holds,
        private SlotGridGenerator $generator = new SlotGridGenerator(),
    ) {
    }

    /**
     * GRID-01, крок 2: для контуру постачальника магазин з ymsStatus ≠ active
     * не існує (404), співробітники мережі бачать його як є.
     */
    public function settingsFor(string $storeId, Actor $actor): StoreSettings
    {
        $settings = $this->storeConfigProvider->settingsFor($storeId);

        if (!$settings->ymsActive && $actor->role->isPartner()) {
            throw new StoreNotFoundException($storeId);
        }

        return $settings;
    }

    public function grid(string $storeId, string $date, Actor $actor, DateTimeImmutable $now): SlotGrid
    {
        return $this->build($this->settingsFor($storeId, $actor), $date, $actor->supplierId, $now);
    }

    public function build(
        StoreSettings $settings,
        string $date,
        ?string $viewerSupplierId,
        DateTimeImmutable $now,
    ): SlotGrid {
        [$from, $to] = self::localDayRange($date);

        $overlays = new SlotOverlays(
            blocks: $this->overlayProvider->blocksFor($settings->storeId(), $from, $to),
            reservedRules: $this->overlayProvider->reservedRulesFor($settings->storeId()),
            bookedKeys: $this->bookings->activeSlotKeys($settings->storeId(), $from, $to),
            heldKeys: $this->holds->activeKeys($settings->storeId(), $from, $to, $now),
        );

        return $this->generator->generate($settings->config, $date, $now, $viewerSupplierId, $overlays);
    }

    /**
     * WALK-03: сітка для позапланового прибуття. Lead time GRID-02 до walk-in
     * не застосовується, а слоти в минулому в межах поточної дати допускаються —
     * машина вже на місці.
     */
    public function buildForWalkIn(StoreSettings $settings, string $date, DateTimeImmutable $now): SlotGrid
    {
        [$from] = self::localDayRange($date);

        return $this->build(
            new StoreSettings(
                config: self::withoutLeadTime($settings->config),
                policy: $settings->policy,
                snapshot: $settings->snapshot,
                ymsActive: $settings->ymsActive,
            ),
            $date,
            null,
            // Точка відліку — початок локальної доби, тому жоден слот
            // сьогоднішнього дня не позначається як past.
            $from,
        );
    }

    /** Знайти конкретний слот сітки за ключем. */
    public function findSlot(SlotGrid $grid, SlotKey $key): ?Slot
    {
        foreach ($grid->slots as $slot) {
            if ($slot->key->equals($key)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Межі локальної доби магазину в UTC.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public static function localDayRange(string $date): array
    {
        $tz = new DateTimeZone(StoreConfig::TIMEZONE);
        $utc = new DateTimeZone('UTC');

        $start = new DateTimeImmutable($date.' 00:00:00', $tz);
        $end = $start->modify('+1 day');

        return [$start->setTimezone($utc), $end->setTimezone($utc)];
    }

    /** Локальна дата магазину для моменту часу. */
    public static function localDate(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(StoreConfig::TIMEZONE))->format('Y-m-d');
    }

    private static function withoutLeadTime(StoreConfig $config): StoreConfig
    {
        return new StoreConfig(
            storeId: $config->storeId,
            receivingWindows: $config->receivingWindows,
            slotSizeMinutes: $config->slotSizeMinutes,
            ramps: $config->ramps,
            maxVehicleWeightTons: $config->maxVehicleWeightTons,
            leadTimeMinutes: 0,
            bookingHorizonDays: $config->bookingHorizonDays,
            calendarExceptions: $config->calendarExceptions,
        );
    }
}
