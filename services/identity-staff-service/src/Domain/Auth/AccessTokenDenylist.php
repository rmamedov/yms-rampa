<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Denylist активних access-токенів за `jti` (AUTH-17, AUTH-28, AUTH-32).
 *
 * Access-токен живе до `exp`, крім критичних випадків (деактивація акаунта,
 * пониження прав super_admin) — тоді `jti` заноситься в Redis із TTL,
 * що дорівнює залишку життя токена, і запит відхиляється не пізніше ніж через 60 с.
 */
interface AccessTokenDenylist
{
    public function revoke(string $jti, \DateTimeImmutable $expiresAt): void;

    public function isRevoked(string $jti): bool;
}
