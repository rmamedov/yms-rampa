<?php

declare(strict_types=1);

namespace App\Domain\Password;

/**
 * Локальний denylist поширених паролів (AUTH-13).
 *
 * SRS вимагає ≥ 100 тис. записів; конкретне джерело (файл, Redis-set)
 * — відповідальність інфраструктури.
 */
interface PasswordDenylist
{
    public function contains(string $plainPassword): bool;
}
