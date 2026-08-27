<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Branch\Branch;
use App\Domain\Branch\IneligibilityReason;
use App\Domain\Configuration\ConfigurationReadiness;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\StoreConfiguration;

/**
 * Побудова JSON-представлень магазину для admin-web (STL-01) і supplier-web (STC-04, STC-07).
 * Усі дати віддаються в UTC ISO 8601 (ADM-03, DATA-01).
 */
final class BranchPresenter
{
    private function __construct()
    {
    }

    /**
     * Рядок таблиці «Список магазинів» (STL-01).
     *
     * @return array<string, mixed>
     */
    public static function row(Branch $branch, ?StoreConfiguration $config): array
    {
        $readiness = $config?->readiness() ?? ConfigurationReadiness::absent();

        return [
            'branchId' => $branch->id(),
            'externalId' => $branch->externalId(),
            'displayName' => $branch->effectiveDisplayName(),
            'city' => $branch->city(),
            'address' => $branch->effectiveAddress(),
            'ymsStatus' => $branch->ymsStatus()->value,
            'ymsStatusLabel' => $branch->ymsStatus()->label(),
            'configured' => $readiness->complete,
            'missingSettings' => $readiness->missing,
            'rampCount' => null === $config ? 0 : \count($config->activeRamps()),
            'maxVehicleWeightTons' => $config?->maxVehicleWeightTons,
            'visibleToSuppliers' => $branch->visibleToSuppliers(),
            'eligible' => $branch->isEligible(),
            'syncedAt' => $branch->syncedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Картка магазину, вкладка «Загальне» (STC-01, STC-02).
     *
     * @return array<string, mixed>
     */
    public static function card(Branch $branch, ?StoreConfiguration $config): array
    {
        $mcp = $branch->mcpData();
        $readiness = $config?->readiness() ?? ConfigurationReadiness::absent();

        return [
            'branchId' => $branch->id(),
            // Read-only блок MCP (INT-03, STC-01).
            'mcpData' => [
                'branchId' => $mcp->branchId,
                'companyId' => $mcp->companyId,
                'externalId' => $mcp->externalId,
                'city' => $mcp->city,
                'address' => $mcp->address,
                'latitude' => $mcp->location?->latitude,
                'longitude' => $mcp->location?->longitude,
                'hasPickup' => $mcp->hasPickup,
                'open' => $mcp->open,
            ],
            // YMS-поля (STC-02).
            'displayName' => $branch->displayName(),
            'effectiveDisplayName' => $branch->effectiveDisplayName(),
            'phone' => $branch->phone(),
            'addressOverride' => $branch->addressOverride(),
            'effectiveAddress' => $branch->effectiveAddress(),
            'ymsStatus' => $branch->ymsStatus()->value,
            'ymsStatusLabel' => $branch->ymsStatus()->label(),
            'allowedTransitions' => array_map(
                static fn ($s): string => $s->value,
                $branch->ymsStatus()->allowedTransitions(),
            ),
            'visibleToSuppliers' => $branch->visibleToSuppliers(),
            'configured' => $readiness->complete,
            'missingSettings' => $readiness->missing,
            'eligible' => $branch->isEligible(),
            'ineligibilityReasons' => array_map(
                static fn (IneligibilityReason $r): array => ['code' => $r->value, 'message' => $r->message()],
                $branch->ineligibilityReasons(),
            ),
            'missingSyncCount' => $branch->missingSyncCount(),
            'syncedAt' => $branch->syncedAt()->format(\DATE_ATOM),
            'createdAt' => $branch->createdAt()->format(\DATE_ATOM),
            'updatedAt' => $branch->updatedAt()->format(\DATE_ATOM),
            'archivedAt' => $branch->archivedAt()?->format(\DATE_ATOM),
            'activeConfigurationVersion' => $config?->version,
        ];
    }

    /**
     * Представлення для supplier-web: без службових MCP-полів, з адресою за STC-07.
     *
     * @return array<string, mixed>
     */
    public static function supplierView(Branch $branch, ?StoreConfiguration $config): array
    {
        $mcp = $branch->mcpData();

        return [
            'storeId' => $branch->id(),
            'externalId' => $mcp->externalId,
            'name' => $branch->effectiveDisplayName(),
            'city' => $mcp->city,
            'address' => $branch->effectiveAddress(),
            'latitude' => $mcp->location?->latitude,
            'longitude' => $mcp->location?->longitude,
            'phone' => $branch->phone(),
            'ramps' => array_map(
                static fn (Ramp $r): array => ['rampId' => $r->rampId, 'number' => $r->number, 'name' => $r->displayName()],
                $config?->activeRamps() ?? [],
            ),
            'maxVehicleWeightTons' => $config?->maxVehicleWeightTons,
            'slotSizeMinutes' => $config?->slotSize->value,
            'leadTimeMinutes' => $config?->leadTimeMinutes,
            'bookingHorizonDays' => $config?->bookingHorizonDays,
        ];
    }
}
