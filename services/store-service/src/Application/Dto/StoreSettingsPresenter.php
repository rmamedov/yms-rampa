<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Branch\Branch;
use App\Domain\Configuration\CalendarException;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\ReceivingWindow;
use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\TimeInterval;
use App\Domain\Shared\Timezone;

/**
 * Службове JSON-представлення чинної конфігурації магазину для booking-service
 * (GET /internal/v1/stores/{storeId}/settings).
 *
 * ЦЕ НЕ ТЕ САМЕ, ЩО ConfigurationPresenter. Адмінський presenter описує документ
 * конфігурації як його редагують у 5.3.2–5.3.6; тут — рівно той набір полів і рівно
 * та форма, яку розбирає споживач (booking-service, Infrastructure\Store\HttpStoreConfigProvider),
 * плюс накладання сітки (резерви і блокування). Відмінності від адмінського формату
 * навмисні, не «неузгодженість»:
 *
 *   - виняток календаря несе БУЛЕВЕ `closed`, а не рядкове `type` (closed|custom):
 *     booking-service читає саме `closed`;
 *   - `validFrom`/`validTo` правила резерву — ЛОКАЛЬНІ ДАТИ Y-m-d, а не мітки часу:
 *     booking-service порівнює їх рядково з датою слота;
 *   - `name` рампи ніколи не null (підставляється «Рампа N»);
 *   - межі блокувань — мітки часу в UTC (DATA-01), бо блокування задається
 *     діапазоном часу, а не датою.
 */
final class StoreSettingsPresenter
{
    private function __construct()
    {
    }

    /**
     * @param list<ReservedSlotRule> $reservedRules чинні правила резервів у межах горизонту
     * @param list<SlotBlock>        $slotBlocks    незняті блокування, що перетинають горизонт
     *
     * @return array<string, mixed>
     */
    public static function settings(
        Branch $branch,
        StoreConfiguration $configuration,
        array $reservedRules,
        array $slotBlocks,
    ): array {
        return [
            'storeId' => $branch->id(),
            // GRID-01, крок 2: booking-service вважає магазин з ymsStatus ≠ active
            // неіснуючим для постачальника; сюди він доїжджає лише зі значенням active.
            'ymsStatus' => $branch->ymsStatus()->value,
            'visibleToSuppliers' => $branch->visibleToSuppliers(),
            // DATA-13: снапшот філії, який booking-service вморожує в документ бронювання.
            'snapshot' => [
                'externalId' => $branch->externalId(),
                'displayName' => $branch->effectiveDisplayName(),
                'city' => $branch->city(),
                'address' => $branch->effectiveAddress(),
            ],
            // Версія чинної конфігурації — для інвалідації кешу подією StoreConfigChanged (SLOT-04).
            'configVersion' => $configuration->version,
            'effectiveFrom' => $configuration->effectiveFrom->format(\DATE_ATOM),
            'receivingWindows' => self::windows($configuration),
            'slotSizeMinutes' => $configuration->slotSize->value,
            'ramps' => self::ramps($configuration),
            'maxVehicleWeightTons' => $configuration->maxVehicleWeightTons,
            'leadTimeMinutes' => $configuration->leadTimeMinutes,
            'bookingHorizonDays' => $configuration->bookingHorizonDays,
            'noShowGraceMinutes' => $configuration->noShowGraceMinutes,
            'holdMaxMinutes' => $configuration->holdMaxMinutes,
            'calendarExceptions' => self::calendarExceptions($configuration),
            'reservedSlotRules' => self::reservedSlotRules($reservedRules),
            'slotBlocks' => self::slotBlocks($slotBlocks),
        ];
    }

    /**
     * @return list<array{dayOfWeek: int, intervals: list<array{from: string, to: string}>}>
     */
    private static function windows(StoreConfiguration $configuration): array
    {
        return array_values(array_map(
            static fn (ReceivingWindow $window): array => [
                'dayOfWeek' => $window->dayOfWeek,
                'intervals' => self::intervals($window->intervals),
            ],
            $configuration->receivingWindows,
        ));
    }

    /**
     * Рампи віддаються ВСІ разом з ознакою active: вимкнену рампу booking-service
     * відсіює сам (StoreConfig::activeRamps), але має знати, що вона існує —
     * інакше бронювання на неї не отримає зрозумілої відмови.
     *
     * @return list<array{rampId: string, number: int, name: string, active: bool}>
     */
    private static function ramps(StoreConfiguration $configuration): array
    {
        return array_values(array_map(
            static fn (Ramp $ramp): array => [
                'rampId' => $ramp->rampId,
                'number' => $ramp->number,
                'name' => $ramp->displayName(),
                'active' => $ramp->active,
            ],
            $configuration->ramps,
        ));
    }

    /**
     * @return list<array{date: string, closed: bool, reason: string, intervals: list<array{from: string, to: string}>}>
     */
    private static function calendarExceptions(StoreConfiguration $configuration): array
    {
        return array_values(array_map(
            static fn (CalendarException $exception): array => [
                'date' => $exception->date,
                'closed' => $exception->isClosed(),
                'reason' => $exception->reason,
                'intervals' => self::intervals($exception->intervals),
            ],
            $configuration->calendarExceptions,
        ));
    }

    /**
     * @param list<ReservedSlotRule> $rules
     *
     * @return list<array<string, mixed>>
     */
    private static function reservedSlotRules(array $rules): array
    {
        return array_values(array_map(
            static fn (ReservedSlotRule $rule): array => [
                'supplierId' => $rule->supplierId,
                'rampId' => $rule->rampId,
                'slotStartTime' => $rule->slotStartTime,
                'dayOfWeek' => $rule->dayOfWeek,
                'date' => $rule->date,
                // Локальні дати магазину: споживач порівнює їх з датою слота як рядки.
                'validFrom' => Timezone::localDate($rule->validFrom),
                'validTo' => null === $rule->validTo ? null : Timezone::localDate($rule->validTo),
                'active' => $rule->active,
            ],
            $rules,
        ));
    }

    /**
     * @param list<SlotBlock> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private static function slotBlocks(array $blocks): array
    {
        return array_values(array_map(
            static fn (SlotBlock $block): array => [
                // Порожній rampIds разом з coversAllRamps=true означає «всі рампи магазину».
                'rampIds' => $block->rampIds,
                'coversAllRamps' => $block->coversAllRamps(),
                'blockFrom' => $block->blockFrom->setTimezone(Timezone::storage())->format(\DATE_ATOM),
                'blockTo' => $block->blockTo->setTimezone(Timezone::storage())->format(\DATE_ATOM),
                'reason' => $block->reason,
            ],
            $blocks,
        ));
    }

    /**
     * @param list<TimeInterval> $intervals
     *
     * @return list<array{from: string, to: string}>
     */
    private static function intervals(array $intervals): array
    {
        return array_values(array_map(
            static fn (TimeInterval $interval): array => $interval->toArray(),
            $intervals,
        ));
    }
}
