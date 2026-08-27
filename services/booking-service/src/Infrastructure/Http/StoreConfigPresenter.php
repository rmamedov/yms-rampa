<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\TimeInterval;
use App\Domain\Store\StoreSettings;

/**
 * Конфігурація магазину у відповіді контуру магазину
 * (GET /api/store/v1/stores/{storeId}/config).
 *
 * ЦЕ НЕ КОПІЯ службового контракту store-service. Тут зібрано рівно те, що
 * малює екран магазину: геометрія сітки (рампи, вікна прийому, розмір слота),
 * ліміт тоннажу і ті параметри движка, від яких залежать підказки інтерфейсу
 * (grace no-show, lead time, горизонт). Резерви і блокування сюди не входять —
 * вони вже враховані в станах слотів сітки.
 *
 * `noShowGraceMinutes` приходить не з геометрії, а з політики магазину
 * (StorePolicy): без нього store-web не може показати, скільки ще лишилося
 * до автоматичного «не приїхав».
 */
final readonly class StoreConfigPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(StoreSettings $settings): array
    {
        $config = $settings->config;

        return [
            'storeId' => $config->storeId,
            'externalId' => $settings->snapshot->externalId,
            'displayName' => $settings->snapshot->displayName,
            'city' => $settings->snapshot->city,
            'address' => $settings->snapshot->address,
            'ramps' => array_map(
                static fn (Ramp $ramp): array => [
                    'rampId' => $ramp->rampId,
                    'name' => $ramp->name,
                    'active' => $ramp->active,
                ],
                $config->ramps,
            ),
            'slotSizeMinutes' => $config->slotSizeMinutes,
            'receivingWindows' => array_map(
                static fn (ReceivingWindow $window): array => [
                    'dayOfWeek' => $window->dayOfWeek,
                    'intervals' => array_map(
                        static fn (TimeInterval $interval): array => [
                            'from' => $interval->formatFrom(),
                            'to' => $interval->formatTo(),
                        ],
                        $window->intervals,
                    ),
                ],
                $config->receivingWindows,
            ),
            'maxVehicleWeightTons' => $config->maxVehicleWeightTons,
            'noShowGraceMinutes' => $settings->policy->noShowGraceMinutes,
            'leadTimeMinutes' => $config->leadTimeMinutes,
            'horizonDays' => $config->bookingHorizonDays,
        ];
    }
}
