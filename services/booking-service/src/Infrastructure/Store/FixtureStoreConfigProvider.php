<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Domain\Booking\StoreSnapshot;
use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\StoreConfig;
use App\Domain\Slot\TimeInterval;
use App\Domain\Store\GeoPoint;
use App\Domain\Store\StoreBrief;
use App\Domain\Store\StoreConfigProvider;
use App\Domain\Store\StoreDirectory;
use App\Domain\Store\StoreNotFoundException;
use App\Domain\Store\StorePolicy;
use App\Domain\Store\StoreSettings;

/**
 * Заглушка конфігурації магазину: віддає зареєстровану фікстуру, а якщо
 * її немає — типову філію з мережевими дефолтами (розділ 6.11).
 *
 * Заодно грає роль довідника філій (StoreDirectory): перелік магазинів у
 * заглушці — це рівно ті, що в неї зареєстрували. Тримати для цього окремий
 * клас із власним списком означало б завести два джерела правди, які легко
 * розсинхронити в тестах.
 *
 * У проді підмінюється HttpStoreConfigProvider і HttpStoreDirectory, які
 * читають store-service.
 */
final class FixtureStoreConfigProvider implements StoreConfigProvider, StoreDirectory
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

    public function listStores(?array $storeIds = null): array
    {
        $stores = [];

        foreach ($this->fixtures as $storeId => $settings) {
            if (null !== $storeIds && !\in_array($storeId, $storeIds, true)) {
                continue;
            }

            // Магазин, відключений від YMS, у перелік не потрапляє — так само,
            // як його не віддає службовий перелік store-service.
            if (!$settings->ymsActive) {
                continue;
            }

            $stores[] = new StoreBrief(
                storeId: $storeId,
                externalId: $settings->snapshot->externalId,
                displayName: $settings->snapshot->displayName,
                city: $settings->snapshot->city,
                address: $settings->snapshot->address,
            );
        }

        return $stores;
    }

    /**
     * Типова філія: вікно прийому 08:00–14:00 у будні та суботу,
     * слот 30 хв, дві рампи, ліміт 20 т, lead time 60 хв, горизонт 14 днів.
     * Координати — Хрещатик, 12: без них контур водія лишився б без маршруту.
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
                ramps: [
                    new Ramp('r1', 'Рампа 1', number: 1),
                    new Ramp('r2', 'Рампа 2', number: 2),
                ],
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
            location: new GeoPoint(50.44740, 30.52210),
        );
    }
}
