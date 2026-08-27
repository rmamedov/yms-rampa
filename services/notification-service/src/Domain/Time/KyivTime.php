<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Локальна зона магазину — Europe/Kyiv.
 *
 * Зберігання дат — у UTC, показ користувачу і обчислення часу нагадувань
 * (NOT-06) — у київській зоні.
 */
final class KyivTime
{
    public const string ZONE = 'Europe/Kyiv';

    private function __construct()
    {
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZONE);
    }

    public static function toLocal(\DateTimeImmutable $utc): \DateTimeImmutable
    {
        return $utc->setTimezone(self::zone());
    }

    public static function toUtc(\DateTimeImmutable $any): \DateTimeImmutable
    {
        return $any->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Дата у форматі, який бачить користувач: 05.09.2026 */
    public static function formatDate(\DateTimeImmutable $utc): string
    {
        return self::toLocal($utc)->format('d.m.Y');
    }

    /** Час у форматі, який бачить користувач: 14:30 */
    public static function formatTime(\DateTimeImmutable $utc): string
    {
        return self::toLocal($utc)->format('H:i');
    }
}
