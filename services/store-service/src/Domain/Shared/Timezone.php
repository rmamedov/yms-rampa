<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Часові зони системи: зберігання — UTC, локальна зона магазину — Europe/Kyiv (DATA-01, ADM-03).
 */
final class Timezone
{
    public const string STORE_LOCAL = 'Europe/Kyiv';
    public const string STORAGE = 'UTC';

    private function __construct()
    {
    }

    public static function storeLocal(): \DateTimeZone
    {
        return new \DateTimeZone(self::STORE_LOCAL);
    }

    public static function storage(): \DateTimeZone
    {
        return new \DateTimeZone(self::STORAGE);
    }

    /** Локальна дата магазину (Y-m-d) для моменту в UTC. */
    public static function localDate(\DateTimeImmutable $utc): string
    {
        return $utc->setTimezone(self::storeLocal())->format('Y-m-d');
    }
}
