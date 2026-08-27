<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Генератор UUID v4 без зовнішніх залежностей.
 *
 * DATA-05: первинні ідентифікатори сутностей — UUID v4 у полі `_id` (BSON string).
 */
final class Uuid
{
    private function __construct()
    {
    }

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        // версія 4
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        // варіант RFC 4122
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

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
    }
}
