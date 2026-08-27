<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/**
 * Результат запуску синхронізації (10.2.3: success | partial | failed).
 * Стан running — технічний маркер активного запуску для блокування
 * повторного старту (INT-05, SYNC-02).
 */
enum SyncStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Виконується',
            self::Success => 'Успіх',
            self::Partial => 'Успіх із конфліктами',
            self::Failed => 'Помилка',
        };
    }
}
