<?php

declare(strict_types=1);

namespace App\Domain\Branch;

/**
 * Правила фільтрації записів MCP (fixtures/README.md).
 *
 * Запис не придатний до активації, якщо виконується хоча б одна умова:
 *  1) externalId починається з delete_;
 *  2) city або address порожні;
 *  3) latitude або longitude відсутні;
 *  4) координати поза bbox України (lat 44.0–52.5, lon 22.0–40.5).
 */
final class BranchEligibility
{
    public const string DELETED_PREFIX = 'delete_';

    private function __construct()
    {
    }

    /**
     * @return list<IneligibilityReason>
     */
    public static function evaluate(McpData $data): array
    {
        $reasons = [];

        if (str_starts_with(mb_strtolower($data->externalId), self::DELETED_PREFIX)) {
            $reasons[] = IneligibilityReason::DeletedExternalId;
        }

        if ('' === trim($data->city)) {
            $reasons[] = IneligibilityReason::EmptyCity;
        }

        if ('' === trim($data->address)) {
            $reasons[] = IneligibilityReason::EmptyAddress;
        }

        if (!$data->location instanceof GeoLocation) {
            $reasons[] = IneligibilityReason::MissingCoordinates;
        } elseif (!$data->location->isWithinUkraine()) {
            $reasons[] = IneligibilityReason::CoordinatesOutsideUkraine;
        }

        return $reasons;
    }

    public static function isEligible(McpData $data): bool
    {
        return [] === self::evaluate($data);
    }
}
