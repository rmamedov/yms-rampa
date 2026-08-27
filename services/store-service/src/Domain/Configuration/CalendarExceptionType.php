<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

/**
 * Тип винятку календаря (STC-12): «вихідний» або «змінений графік».
 */
enum CalendarExceptionType: string
{
    case Closed = 'closed';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Closed => 'Вихідний',
            self::Custom => 'Змінений графік',
        };
    }
}
