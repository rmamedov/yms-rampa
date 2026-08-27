<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo\Mapper;

use App\Domain\Branch\Branch;
use App\Domain\Branch\GeoLocation;
use App\Domain\Branch\IneligibilityReason;
use App\Domain\Branch\McpData;
use App\Domain\Branch\YmsStatus;
use App\Infrastructure\Mongo\MongoConnection;

/**
 * Мапінг агрегата Branch у документ колекції `branches` і назад (схема 10.2.1).
 */
final class BranchDocumentMapper
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function toDocument(Branch $branch): array
    {
        $mcp = $branch->mcpData();

        return [
            '_id' => $branch->id(),
            'companyId' => $mcp->companyId,
            'externalId' => $mcp->externalId,
            'city' => $mcp->city,
            'address' => $mcp->address,
            'location' => $mcp->location?->toGeoJson(),
            'open' => $mcp->open,
            'hasPickup' => $mcp->hasPickup,
            'syncedAt' => MongoConnection::fromDateTime($branch->syncedAt()),
            'displayName' => $branch->displayName(),
            'phone' => $branch->phone(),
            'addressOverride' => $branch->addressOverride(),
            'ymsStatus' => $branch->ymsStatus()->value,
            'visibleToSuppliers' => $branch->visibleToSuppliers(),
            'missingSyncCount' => $branch->missingSyncCount(),
            'ineligibilityReasons' => array_map(
                static fn (IneligibilityReason $r): string => $r->value,
                $branch->ineligibilityReasons(),
            ),
            'schemaVersion' => Branch::SCHEMA_VERSION,
            'createdAt' => MongoConnection::fromDateTime($branch->createdAt()),
            'updatedAt' => MongoConnection::fromDateTime($branch->updatedAt()),
            'archivedAt' => MongoConnection::fromDateTime($branch->archivedAt()),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): Branch
    {
        $location = $document['location'] ?? null;
        $coordinates = \is_array($location) ? ($location['coordinates'] ?? null) : null;

        $geo = null;

        if (\is_array($coordinates) && isset($coordinates[0], $coordinates[1])) {
            $geo = new GeoLocation((float) $coordinates[1], (float) $coordinates[0]);
        }

        $mcp = new McpData(
            branchId: (string) $document['_id'],
            companyId: (string) ($document['companyId'] ?? ''),
            externalId: (string) ($document['externalId'] ?? ''),
            city: (string) ($document['city'] ?? ''),
            address: (string) ($document['address'] ?? ''),
            location: $geo,
            hasPickup: (bool) ($document['hasPickup'] ?? false),
            open: (bool) ($document['open'] ?? false),
        );

        $reasons = [];

        foreach ((array) ($document['ineligibilityReasons'] ?? []) as $raw) {
            $reason = IneligibilityReason::tryFrom((string) $raw);

            if ($reason instanceof IneligibilityReason) {
                $reasons[] = $reason;
            }
        }

        $syncedAt = MongoConnection::toDateTime($document['syncedAt'] ?? null) ?? new \DateTimeImmutable('@0');

        return Branch::restore(
            mcpData: $mcp,
            syncedAt: $syncedAt,
            ymsStatus: YmsStatus::tryFrom((string) ($document['ymsStatus'] ?? '')) ?? YmsStatus::NotConfigured,
            visibleToSuppliers: (bool) ($document['visibleToSuppliers'] ?? false),
            missingSyncCount: (int) ($document['missingSyncCount'] ?? 0),
            ineligibilityReasons: $reasons,
            displayName: self::nullableString($document['displayName'] ?? null),
            phone: self::nullableString($document['phone'] ?? null),
            addressOverride: self::nullableString($document['addressOverride'] ?? null),
            createdAt: MongoConnection::toDateTime($document['createdAt'] ?? null) ?? $syncedAt,
            updatedAt: MongoConnection::toDateTime($document['updatedAt'] ?? null) ?? $syncedAt,
            archivedAt: MongoConnection::toDateTime($document['archivedAt'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }
}
