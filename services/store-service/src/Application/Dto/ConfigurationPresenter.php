<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Configuration\CalendarException;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\ReceivingWindow;
use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\TimeInterval;

/**
 * JSON-представлення конфігурації магазину, правил резервів і блокувань (10.2.2, 10.2.3).
 */
final class ConfigurationPresenter
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function configuration(StoreConfiguration $config): array
    {
        $readiness = $config->readiness();

        return [
            'id' => $config->id,
            'storeId' => $config->storeId,
            'version' => $config->version,
            'effectiveFrom' => $config->effectiveFrom->format(\DATE_ATOM),
            'receivingWindows' => array_values(array_map(
                static fn (ReceivingWindow $w): array => [
                    'dayOfWeek' => $w->dayOfWeek,
                    'intervals' => array_map(static fn (TimeInterval $i): array => $i->toArray(), $w->intervals),
                ],
                $config->receivingWindows,
            )),
            'slotSizeMinutes' => $config->slotSize->value,
            'ramps' => array_map(static fn (Ramp $r): array => $r->toArray(), $config->ramps),
            'maxVehicleWeightTons' => $config->maxVehicleWeightTons,
            'leadTimeMinutes' => $config->leadTimeMinutes,
            'bookingHorizonDays' => $config->bookingHorizonDays,
            'noShowGraceMinutes' => $config->noShowGraceMinutes,
            'holdMaxMinutes' => $config->holdMaxMinutes,
            'calendarExceptions' => array_values(array_map(
                static fn (CalendarException $e): array => [
                    'date' => $e->date,
                    'type' => $e->type->value,
                    'reason' => $e->reason,
                    'intervals' => array_map(static fn (TimeInterval $i): array => $i->toArray(), $e->intervals),
                ],
                $config->calendarExceptions,
            )),
            'configured' => $readiness->complete,
            'missingSettings' => $readiness->missing,
            'createdBy' => $config->createdBy,
            'createdAt' => $config->createdAt?->format(\DATE_ATOM),
            'schemaVersion' => StoreConfiguration::SCHEMA_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function reservedSlotRule(ReservedSlotRule $rule): array
    {
        return [
            'id' => $rule->id,
            'storeId' => $rule->storeId,
            'supplierId' => $rule->supplierId,
            'rampId' => $rule->rampId,
            'slotStartTime' => $rule->slotStartTime,
            'dayOfWeek' => $rule->dayOfWeek,
            'date' => $rule->date,
            'validFrom' => $rule->validFrom->format(\DATE_ATOM),
            'validTo' => $rule->validTo?->format(\DATE_ATOM),
            'active' => $rule->active,
            'createdBy' => $rule->createdBy,
            'createdAt' => $rule->createdAt?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function slotBlock(SlotBlock $block): array
    {
        return [
            'id' => $block->id,
            'storeId' => $block->storeId,
            'rampIds' => $block->rampIds,
            'coversAllRamps' => $block->coversAllRamps(),
            'blockFrom' => $block->blockFrom->format(\DATE_ATOM),
            'blockTo' => $block->blockTo->format(\DATE_ATOM),
            'reason' => $block->reason,
            'releasedAt' => $block->releasedAt?->format(\DATE_ATOM),
            'createdBy' => $block->createdBy,
            'createdAt' => $block->createdAt?->format(\DATE_ATOM),
        ];
    }
}
