<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Мінімальний генератор/валідатор UUID v4 (DATA-05: первинні ідентифікатори — UUID v4 у _id).
 */
final class Uuid
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct()
    {
    }

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Перевіряє формат UUID. MCP віддає UUID v6/v7-подібні значення (напр. 1ed43e73-...),
     * тому версія не перевіряється — лише канонічна форма (INT-14).
     */
    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }
}
