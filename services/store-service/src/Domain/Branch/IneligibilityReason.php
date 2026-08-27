<?php

declare(strict_types=1);

namespace App\Domain\Branch;

/**
 * Причини непридатності запису MCP до активації (правила фільтрації, fixtures/README.md).
 *
 * Такі записи все одно імпортуються в довідник зі статусом not_configured,
 * але не можуть бути активовані і ніколи не показуються постачальникам.
 */
enum IneligibilityReason: string
{
    case DeletedExternalId = 'deleted_external_id';
    case EmptyCity = 'empty_city';
    case EmptyAddress = 'empty_address';
    case MissingCoordinates = 'missing_coordinates';
    case CoordinatesOutsideUkraine = 'coordinates_outside_ukraine';

    public function message(): string
    {
        return match ($this) {
            self::DeletedExternalId => 'Філію видалено в MCP (externalId починається з delete_)',
            self::EmptyCity => 'У даних MCP не заповнено місто',
            self::EmptyAddress => 'У даних MCP не заповнено адресу',
            self::MissingCoordinates => 'У даних MCP відсутні координати',
            self::CoordinatesOutsideUkraine => 'Координати філії поза межами України',
        };
    }
}
