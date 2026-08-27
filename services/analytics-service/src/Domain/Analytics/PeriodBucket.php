<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * Побудова ключів часових розрізів день / тиждень / місяць (KPI-05).
 *
 * Зберігання часу — UTC, але бізнес-доба магазину рахується в локальній зоні
 * Europe/Kyiv, тому бакет обчислюється після переведення моменту в цю зону.
 */
final readonly class PeriodBucket
{
    public const STORE_TIME_ZONE = 'Europe/Kyiv';

    public static function storeTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone(self::STORE_TIME_ZONE);
    }

    /** Ключ доби: 2026-03-14 (локальна доба магазину). */
    public static function day(\DateTimeImmutable $moment): string
    {
        return self::local($moment)->format('Y-m-d');
    }

    /** Ключ ISO-тижня: 2026-W11. */
    public static function week(\DateTimeImmutable $moment): string
    {
        return self::local($moment)->format('o-\WW');
    }

    /** Ключ місяця: 2026-03. */
    public static function month(\DateTimeImmutable $moment): string
    {
        return self::local($moment)->format('Y-m');
    }

    public static function local(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(self::storeTimeZone());
    }
}
