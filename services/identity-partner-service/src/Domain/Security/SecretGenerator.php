<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Генерація ідентифікаторів і непрозорих (opaque) секретів.
 *
 * DATA-05: первинні ідентифікатори — UUID v4.
 * AUTH-30 / DATA-19: самі refresh-токени не зберігаються — лише SHA-256-хеш.
 */
final readonly class SecretGenerator
{
    /** UUID v4 (DATA-05). */
    public function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Непрозорий refresh-токен: 256 біт ентропії в hex. */
    public function newOpaqueToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** SHA-256-хеш токена — саме він лягає в БД (AUTH-30). */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
