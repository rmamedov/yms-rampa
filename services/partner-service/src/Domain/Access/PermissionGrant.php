<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Тип надання права у матриці 4.4 SRS.
 *
 * ✓ — повне право в межах контуру (уся мережа);
 * S — право лише в межах скоупа (4.5): свій постачальник або свої магазини;
 * — — заборонено.
 *
 * RBAC-02 (deny by default): усе, чого немає в матриці, — заборонено.
 */
enum PermissionGrant: string
{
    case Full = '✓';
    case Scoped = 'S';
    case Denied = '—';

    public static function fromSymbol(string $symbol): self
    {
        return self::tryFrom($symbol) ?? throw new \LogicException(
            \sprintf('Невідомий символ матриці прав «%s».', $symbol),
        );
    }

    public function isGranted(): bool
    {
        return self::Denied !== $this;
    }
}
