<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Domain\Booking\StoreSnapshot;
use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\StoreConfig;
use App\Domain\Slot\TimeInterval;
use App\Domain\Store\StoreConfigProvider;
use App\Domain\Store\StoreNotFoundException;
use App\Domain\Store\StorePolicy;
use App\Domain\Store\StoreSettings;

/**
 * Заглушка конфігурації магазину: віддає зареєстровану фікстуру, а якщо
 * її немає — типову філію з мережевими дефолтами (розділ 6.11).
 *
 * У проді підмінюється HttpStoreConfigProvider, який читає store-service.
 */
final class FixtureStoreConfigProvider implements StoreConfigProvider
{
    /** @var array<string, StoreSettings> */
    private array $fixtures = [];

    /**
     * @param bool $strict true — невідомий магазин викликає STORE_NOT_FOUND
     *                     замість типової філії (режим тестів)
     */
    public function __construct(private readonly bool $strict = false)
    {
    }

    public function register(StoreSettings $settings): void
    {
        $this->fixtures[$settings->storeId()] = $settings;
    }

    public function settingsFor(string $storeId): StoreSettings
    {
        if (isset($this->fixtures[$storeId])) {
            return $this->fixtures[$storeId];
        }

        if ($this->strict) {
            throw new StoreNotFoundException($storeId);
        }

        return self::defaultSettings($storeId);
    }

    /**
     * Типова філія: вікно прийому 08:00–14:00 у будні та суботу,
     * слот 30 хв, дві рампи, ліміт 20 т, lead time 60 хв, горизонт 14 днів.
     */
    public static function defaultSettings(string $storeId): StoreSettings
    {
        $windows = [];
        for ($day = 1; $day <= 6; ++$day) {
            $windows[] = new ReceivingWindow($day, [new TimeInterval('08:00', '14:00')]);
        }

        return new StoreSettings(
            config: new StoreConfig(
                storeId: $storeId,
                receivingWindows: $windows,
                slotSizeMinutes: 30,
                ramps: [new Ramp('r1', 'Рампа 1'), new Ramp('r2', 'Рампа 2')],
                maxVehicleWeightTons: 20.0,
                leadTimeMinutes: 60,
                bookingHorizonDays: 14,
            ),
            policy: new StorePolicy(),
            snapshot: new StoreSnapshot(
                externalId: $storeId,
                displayName: 'Сільпо (демо-філія)',
                city: 'Київ',
                address: 'вул. Хрещатик, 12',
            ),
        );
    }
}
