<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/** Що саме сталося з філією під час синхронізації (INT-07, INT-08, INT-09). */
enum BranchChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Missing = 'missing';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Нова',
            self::Updated => 'Змінена',
            self::Missing => 'Зникла з MCP',
            self::Archived => 'Заархівована',
        };
    }
}
