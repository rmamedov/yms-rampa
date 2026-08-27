<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Контур автентифікації.
 *
 * AUTH-01/AUTH-03/RBAC-03: два повністю ізольовані контури з окремими
 * ключовими парами JWT та різними `iss`/`aud`.
 */
enum Contour: string
{
    case Staff = 'staff';
    case Partner = 'partner';

    /**
     * AUTH-03: клейм `iss`.
     */
    public function issuer(): string
    {
        return match ($this) {
            self::Staff => 'yms-staff',
            self::Partner => 'yms-partner',
        };
    }

    /**
     * AUTH-03: клейм `aud`.
     */
    public function audience(): string
    {
        return match ($this) {
            self::Staff => 'yms-staff-api',
            self::Partner => 'yms-partner-api',
        };
    }

    /**
     * RBAC-19: префікси маршрутів контуру.
     *
     * @return list<string>
     */
    public function routePrefixes(): array
    {
        return match ($this) {
            self::Staff => ['/api/admin/v1', '/api/store/v1'],
            self::Partner => ['/api/supplier/v1', '/api/driver/v1'],
        };
    }
}
