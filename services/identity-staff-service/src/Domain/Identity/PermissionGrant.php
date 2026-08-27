<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Легенда матриці «ролі × права» (RBAC-10).
 *
 * ✓ — право надано в межах контуру;
 * S — право надано лише в межах скоупа (4.5);
 * P — лише публічні атрибути активних магазинів (ymsStatus=active);
 * — — заборонено.
 */
enum PermissionGrant: string
{
    case Full = 'full';
    case Scoped = 'scoped';
    case PublicOnly = 'public';
    case Denied = 'denied';

    /**
     * Символ із таблиці 4.4 — використовується у дампі матриці та в тестах.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Full => '✓',
            self::Scoped => 'S',
            self::PublicOnly => 'P',
            self::Denied => '—',
        };
    }

    public static function fromSymbol(string $symbol): self
    {
        return match ($symbol) {
            '✓' => self::Full,
            'S', 'S*' => self::Scoped,
            'P' => self::PublicOnly,
            '—', '-' => self::Denied,
            default => throw new \InvalidArgumentException(
                \sprintf('Невідомий символ матриці прав: "%s".', $symbol),
            ),
        };
    }

    public function isGranted(): bool
    {
        return self::Denied !== $this;
    }
}
