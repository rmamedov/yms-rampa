<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/**
 * Тип запуску синхронізації (SYNC-01: авто / ручний, з іменем ініціатора).
 */
enum SyncTrigger: string
{
    case Cron = 'cron';
    case Manual = 'manual';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Cron => 'Автоматичний (cron)',
            self::Manual => 'Ручний',
            self::Import => 'Первинний імпорт',
        };
    }
}
