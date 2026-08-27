<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Shared\IdGenerator;

/**
 * Генератор UUID v4 (DATA-05) на криптостійкому джерелі random_bytes().
 * Власна реалізація, щоб не тягнути зайву залежність у сервіс.
 */
final class Uuid4Generator implements IdGenerator
{
    public function generate(): string
    {
        $bytes = random_bytes(16);

        // Версія 4 і варіант RFC 4122.
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
