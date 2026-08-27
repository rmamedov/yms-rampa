<?php

declare(strict_types=1);

namespace App\Domain\Branch;

use App\Domain\Shared\Uuid;
use App\Domain\Shared\ValidationException;

/**
 * Read-only блок даних MCP Сільпо (INT-03). Жодна роль, включно з super_admin,
 * не може редагувати ці поля — вони змінюються лише синхронізацією.
 */
final readonly class McpData
{
    public function __construct(
        public string $branchId,
        public string $companyId,
        public string $externalId,
        public string $city,
        public string $address,
        public ?GeoLocation $location,
        public bool $hasPickup,
        public bool $open,
    ) {
        if (!Uuid::isValid($branchId)) {
            throw ValidationException::field('branchId', 'branchId має бути валідним UUID');
        }

        if ('' === trim($externalId)) {
            throw ValidationException::field('externalId', 'externalId не може бути порожнім');
        }

        if (mb_strlen($city) > 100) {
            throw ValidationException::field('city', 'Назва міста не може перевищувати 100 символів');
        }
    }

    /**
     * Створення з сирого запису MCP. Порушення контракту (немає branchId, невалідний UUID)
     * відхиляється на рівні запису — виклик обробляє це як пропуск (INT-14).
     * hasPickup=null нормалізується у false (fixtures/README.md).
     *
     * @param array<string, mixed> $row
     */
    public static function fromMcpRow(array $row): self
    {
        $branchId = self::str($row, 'branchId');

        if (null === $branchId) {
            throw ValidationException::field('branchId', 'Запис MCP без branchId відхилено');
        }

        $lat = self::floatOrNull($row['latitude'] ?? null);
        $lng = self::floatOrNull($row['longitude'] ?? null);

        return new self(
            branchId: $branchId,
            companyId: self::str($row, 'companyId') ?? '',
            externalId: trim((string) ($row['externalId'] ?? '')),
            city: trim((string) ($row['city'] ?? '')),
            address: trim((string) ($row['address'] ?? '')),
            location: (null !== $lat && null !== $lng) ? new GeoLocation($lat, $lng) : null,
            hasPickup: (bool) ($row['hasPickup'] ?? false),
            open: (bool) ($row['open'] ?? false),
        );
    }

    /**
     * Перелік MCP-полів, що відрізняються (SYNC-03: diff «старе/нове значення»).
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(self $other): array
    {
        $changes = [];

        foreach (['companyId', 'externalId', 'city', 'address', 'hasPickup', 'open'] as $field) {
            if ($this->{$field} !== $other->{$field}) {
                $changes[$field] = ['old' => $this->{$field}, 'new' => $other->{$field}];
            }
        }

        $sameLocation = null === $this->location
            ? null === $other->location
            : $this->location->equals($other->location);

        if (!$sameLocation) {
            $changes['location'] = [
                'old' => $this->location?->toGeoJson(),
                'new' => $other->location?->toGeoJson(),
            ];
        }

        return $changes;
    }

    public function equals(self $other): bool
    {
        return [] === $this->diff($other);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }

    private static function floatOrNull(mixed $value): ?float
    {
        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
