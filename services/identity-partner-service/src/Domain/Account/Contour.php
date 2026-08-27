<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Контур автентифікації (AUTH-01, AUTH-03).
 *
 * Контури повністю ізольовані: окремі ключові пари JWT, окремі iss/aud,
 * окремі бази. Спільних облікових записів між контурами не існує (AUTH-04).
 */
enum Contour: string
{
    case Staff = 'staff';
    case Partner = 'partner';

    /** Канонічний issuer контуру (AUTH-03). */
    public function issuer(): string
    {
        return match ($this) {
            self::Staff => 'yms-staff',
            self::Partner => 'yms-partner',
        };
    }

    /** Канонічна audience контуру (AUTH-03). */
    public function audience(): string
    {
        return match ($this) {
            self::Staff => 'yms-staff-api',
            self::Partner => 'yms-partner-api',
        };
    }
}
