<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Identity\StaffUser;

/**
 * AUTH-11: успішний логін повертає пару токенів і профіль користувача
 * (імʼя, роль RBAC в однині, scope — storeIds або ознака доступу до всієї мережі).
 */
final readonly class LoginResult
{
    public function __construct(
        public StaffUser $user,
        public IssuedTokens $tokens,
    ) {
    }

    /**
     * Профіль для тіла відповіді логіну/refresh.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return [
            'id' => $this->user->id(),
            'email' => $this->user->email()->value,
            'fullName' => $this->user->fullName(),
            // RBAC-04: роль в однині
            'role' => $this->user->role()->value,
            'roleLabel' => $this->user->role()->label(),
            'scope' => [
                'storeIds' => $this->user->storeIds(),
                // RBAC-16: ознака доступу до всієї мережі
                'networkWide' => $this->user->isNetworkWide(),
            ],
            'twoFactorEnabled' => $this->user->isTwoFactorEnabled(),
            'permissions' => array_map(
                static fn ($permission): string => $permission->value,
                $this->user->role()->permissions(),
            ),
        ];
    }
}
