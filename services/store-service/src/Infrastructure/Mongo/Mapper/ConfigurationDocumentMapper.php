<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo\Mapper;

use App\Domain\Configuration\CalendarException;
use App\Domain\Configuration\CalendarExceptionType;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\ReceivingWindow;
use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\SlotSize;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\TimeInterval;
use App\Infrastructure\Mongo\MongoConnection;

/**
 * Мапінг конфігурації магазину, правил резервів і блокувань у документи
 * колекцій store_configs, reserved_slot_rules, slot_blocks (10.2.2, 10.2.3).
 */
final class ConfigurationDocumentMapper
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function configToDocument(StoreConfiguration $config): array
    {
        return [
            '_id' => $config->id,
            'storeId' => $config->storeId,
            'version' => $config->version,
            'effectiveFrom' => MongoConnection::fromDateTime($config->effectiveFrom),
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
            'horizonDays' => $config->bookingHorizonDays,
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
            'createdBy' => $config->createdBy,
            'schemaVersion' => StoreConfiguration::SCHEMA_VERSION,
            'createdAt' => MongoConnection::fromDateTime($config->createdAt),
            'updatedAt' => MongoConnection::fromDateTime($config->createdAt),
            'archivedAt' => MongoConnection::fromDateTime($config->archivedAt),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function configFromDocument(array $document): StoreConfiguration
    {
        $windows = [];

        foreach ((array) ($document['receivingWindows'] ?? []) as $raw) {
            $raw = (array) $raw;
            $windows[] = new ReceivingWindow(
                (int) ($raw['dayOfWeek'] ?? 1),
                self::intervals($raw['intervals'] ?? []),
            );
        }

        $ramps = [];

        foreach ((array) ($document['ramps'] ?? []) as $raw) {
            $raw = (array) $raw;
            $ramps[] = new Ramp(
                rampId: (string) ($raw['rampId'] ?? ''),
                number: (int) ($raw['number'] ?? 1),
                name: isset($raw['name']) && '' !== (string) $raw['name'] ? (string) $raw['name'] : null,
                active: (bool) ($raw['active'] ?? true),
            );
        }

        $exceptions = [];

        foreach ((array) ($document['calendarExceptions'] ?? []) as $raw) {
            $raw = (array) $raw;
            $exceptions[] = new CalendarException(
                date: (string) ($raw['date'] ?? ''),
                type: CalendarExceptionType::tryFrom((string) ($raw['type'] ?? 'closed')) ?? CalendarExceptionType::Closed,
                reason: (string) ($raw['reason'] ?? '—'),
                intervals: self::intervals($raw['intervals'] ?? []),
            );
        }

        return new StoreConfiguration(
            id: (string) $document['_id'],
            storeId: (string) ($document['storeId'] ?? ''),
            version: (int) ($document['version'] ?? 1),
            effectiveFrom: MongoConnection::toDateTime($document['effectiveFrom'] ?? null) ?? new \DateTimeImmutable('@0'),
            receivingWindows: $windows,
            slotSize: SlotSize::fromMinutes((int) ($document['slotSizeMinutes'] ?? 30)),
            ramps: $ramps,
            maxVehicleWeightTons: (float) ($document['maxVehicleWeightTons'] ?? 1.0),
            leadTimeMinutes: (int) ($document['leadTimeMinutes'] ?? StoreConfiguration::LEAD_TIME_DEFAULT),
            bookingHorizonDays: (int) ($document['horizonDays'] ?? StoreConfiguration::HORIZON_DEFAULT_DAYS),
            noShowGraceMinutes: (int) ($document['noShowGraceMinutes'] ?? StoreConfiguration::NO_SHOW_GRACE_DEFAULT),
            holdMaxMinutes: (int) ($document['holdMaxMinutes'] ?? StoreConfiguration::HOLD_MAX_DEFAULT),
            calendarExceptions: $exceptions,
            createdBy: isset($document['createdBy']) ? (string) $document['createdBy'] : null,
            createdAt: MongoConnection::toDateTime($document['createdAt'] ?? null),
            archivedAt: MongoConnection::toDateTime($document['archivedAt'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function ruleToDocument(ReservedSlotRule $rule): array
    {
        return [
            '_id' => $rule->id,
            'storeId' => $rule->storeId,
            'supplierId' => $rule->supplierId,
            'rampId' => $rule->rampId,
            'slotStartTime' => $rule->slotStartTime,
            'dayOfWeek' => $rule->dayOfWeek,
            'date' => $rule->date,
            'validFrom' => MongoConnection::fromDateTime($rule->validFrom),
            'validTo' => MongoConnection::fromDateTime($rule->validTo),
            'active' => $rule->active,
            'createdBy' => $rule->createdBy,
            'schemaVersion' => ReservedSlotRule::SCHEMA_VERSION,
            'createdAt' => MongoConnection::fromDateTime($rule->createdAt),
            'updatedAt' => MongoConnection::fromDateTime($rule->createdAt),
            'archivedAt' => null,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function ruleFromDocument(array $document): ReservedSlotRule
    {
        return new ReservedSlotRule(
            id: (string) $document['_id'],
            storeId: (string) ($document['storeId'] ?? ''),
            supplierId: (string) ($document['supplierId'] ?? ''),
            rampId: (string) ($document['rampId'] ?? ''),
            slotStartTime: (string) ($document['slotStartTime'] ?? '00:00'),
            dayOfWeek: isset($document['dayOfWeek']) && null !== $document['dayOfWeek'] ? (int) $document['dayOfWeek'] : null,
            date: isset($document['date']) && null !== $document['date'] ? (string) $document['date'] : null,
            validFrom: MongoConnection::toDateTime($document['validFrom'] ?? null) ?? new \DateTimeImmutable('@0'),
            validTo: MongoConnection::toDateTime($document['validTo'] ?? null),
            active: (bool) ($document['active'] ?? true),
            createdBy: isset($document['createdBy']) ? (string) $document['createdBy'] : null,
            createdAt: MongoConnection::toDateTime($document['createdAt'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function blockToDocument(SlotBlock $block): array
    {
        return [
            '_id' => $block->id,
            'storeId' => $block->storeId,
            'rampIds' => $block->rampIds,
            'blockFrom' => MongoConnection::fromDateTime($block->blockFrom),
            'blockTo' => MongoConnection::fromDateTime($block->blockTo),
            'reason' => $block->reason,
            'createdBy' => $block->createdBy,
            'releasedAt' => MongoConnection::fromDateTime($block->releasedAt),
            'schemaVersion' => SlotBlock::SCHEMA_VERSION,
            'createdAt' => MongoConnection::fromDateTime($block->createdAt),
            'updatedAt' => MongoConnection::fromDateTime($block->createdAt),
            'archivedAt' => null,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function blockFromDocument(array $document): SlotBlock
    {
        $rampIds = [];

        foreach ((array) ($document['rampIds'] ?? []) as $rampId) {
            $rampIds[] = (string) $rampId;
        }

        return new SlotBlock(
            id: (string) $document['_id'],
            storeId: (string) ($document['storeId'] ?? ''),
            rampIds: $rampIds,
            blockFrom: MongoConnection::toDateTime($document['blockFrom'] ?? null) ?? new \DateTimeImmutable('@0'),
            blockTo: MongoConnection::toDateTime($document['blockTo'] ?? null) ?? new \DateTimeImmutable('@1'),
            reason: (string) ($document['reason'] ?? '—'),
            createdBy: isset($document['createdBy']) ? (string) $document['createdBy'] : null,
            createdAt: MongoConnection::toDateTime($document['createdAt'] ?? null),
            releasedAt: MongoConnection::toDateTime($document['releasedAt'] ?? null),
        );
    }

    /**
     * @return list<TimeInterval>
     */
    private static function intervals(mixed $raw): array
    {
        $intervals = [];

        foreach ((array) $raw as $item) {
            $item = (array) $item;
            $intervals[] = new TimeInterval((string) ($item['from'] ?? '00:00'), (string) ($item['to'] ?? '00:05'));
        }

        return $intervals;
    }
}
