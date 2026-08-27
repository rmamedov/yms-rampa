<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared;

use App\Domain\Shared\IdGenerator;

/**
 * Генератор UUID v4 без зовнішніх залежностей — ідентифікатори бронювань
 * і маршрутних листів зберігаються як рядки (розділ 10.3).
 */
final readonly class RandomIdGenerator implements IdGenerator
{
    public function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
