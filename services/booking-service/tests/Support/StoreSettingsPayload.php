<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Тіло відповіді store-service на GET /internal/v1/stores/{storeId}/settings —
 * рівно в тій формі, яку сусід віддає насправді (склад і порядок ключів
 * узгоджені з його StoreSettingsPresenter).
 *
 * Блок `snapshot` несе не лише назву й адресу, а й координати філії: без них
 * контур водія відкривав би навігатор пошуковим рядком замість точки на карті.
 *
 * Фікстура навмисно «незручна»: три рампи, з яких одна вимкнена, два вікна
 * прийому з різною кількістю інтервалів, обидва типи винятків календаря,
 * щотижневий і разовий резерв, блокування на одну рампу, на дві і на всі.
 * Так мапінг перевіряється на межах, а не на ідеальному прикладі.
 */
final class StoreSettingsPayload
{
    public const string STORE_ID = '9f3b1c2e-0f7a-4d5b-8c6e-1a2b3c4d5e6f';

    /**
     * @param array<string, mixed> $overrides поля, які тест хоче зіпсувати або уточнити
     *
     * @return array<string, mixed>
     */
    public static function full(array $overrides = []): array
    {
        return array_replace([
            'storeId' => self::STORE_ID,
            'ymsStatus' => 'active',
            'visibleToSuppliers' => true,
            'snapshot' => [
                'externalId' => '00123',
                'displayName' => 'Сільпо на Хрещатику',
                'city' => 'Київ',
                'address' => 'вул. Хрещатик, 12',
                // Координати їдуть у тому ж блоці — за ними контур водія
                // будує маршрут у навігаторі (DRV-21).
                'latitude' => 50.49699,
                'longitude' => 30.36123,
            ],
            'configVersion' => 7,
            'effectiveFrom' => '2026-08-01T00:00:00+00:00',
            'receivingWindows' => [
                [
                    'dayOfWeek' => 1,
                    'intervals' => [
                        ['from' => '08:00', 'to' => '12:00'],
                        ['from' => '13:00', 'to' => '18:00'],
                    ],
                ],
                [
                    'dayOfWeek' => 2,
                    'intervals' => [['from' => '08:00', 'to' => '14:00']],
                ],
            ],
            'slotSizeMinutes' => 30,
            'ramps' => [
                ['rampId' => 'r1', 'number' => 1, 'name' => 'Рампа 1', 'active' => true],
                ['rampId' => 'r2', 'number' => 2, 'name' => 'Холодильна', 'active' => true],
                ['rampId' => 'r3', 'number' => 3, 'name' => 'Рампа 3', 'active' => false],
            ],
            'maxVehicleWeightTons' => 12.5,
            'leadTimeMinutes' => 120,
            'bookingHorizonDays' => 21,
            'noShowGraceMinutes' => 45,
            'holdMaxMinutes' => 20,
            'calendarExceptions' => [
                ['date' => '2026-08-24', 'closed' => true, 'reason' => 'День Незалежності', 'intervals' => []],
                [
                    'date' => '2026-08-28',
                    'closed' => false,
                    'reason' => 'Скорочений день',
                    'intervals' => [['from' => '09:00', 'to' => '11:00']],
                ],
            ],
            'reservedSlotRules' => [
                [
                    'supplierId' => 'sp-1',
                    'rampId' => 'r1',
                    'slotStartTime' => '09:00',
                    'dayOfWeek' => 2,
                    'date' => null,
                    'validFrom' => '2026-08-01',
                    'validTo' => null,
                    'active' => true,
                ],
                [
                    'supplierId' => 'sp-2',
                    'rampId' => 'r2',
                    'slotStartTime' => '10:30',
                    'dayOfWeek' => null,
                    'date' => '2026-09-03',
                    'validFrom' => '2026-08-01',
                    'validTo' => '2026-09-03',
                    'active' => true,
                ],
            ],
            'slotBlocks' => [
                [
                    'rampIds' => ['r1'],
                    'coversAllRamps' => false,
                    'blockFrom' => '2026-08-28T05:00:00+00:00',
                    'blockTo' => '2026-08-28T07:00:00+00:00',
                    'reason' => 'Ремонт воріт',
                ],
                [
                    'rampIds' => ['r1', 'r2'],
                    'coversAllRamps' => false,
                    'blockFrom' => '2026-08-29T06:00:00+00:00',
                    'blockTo' => '2026-08-29T08:00:00+00:00',
                    'reason' => 'Приймання власного імпорту',
                ],
                [
                    'rampIds' => [],
                    'coversAllRamps' => true,
                    'blockFrom' => '2026-09-01T05:00:00+00:00',
                    'blockTo' => '2026-09-01T15:00:00+00:00',
                    'reason' => 'Інвентаризація',
                ],
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function json(array $overrides = []): string
    {
        return json_encode(self::full($overrides), \JSON_THROW_ON_ERROR);
    }

    /** Тіло problem+json, яким store-service відповідає на невідому філію. */
    public static function notFoundProblem(string $code = 'STORE_NOT_FOUND'): string
    {
        return json_encode([
            'type' => 'about:blank',
            'title' => 'Не знайдено',
            'status' => 404,
            'detail' => 'Філію не знайдено',
            'code' => $code,
            'requestId' => 'req-store-1',
        ], \JSON_THROW_ON_ERROR);
    }
}
