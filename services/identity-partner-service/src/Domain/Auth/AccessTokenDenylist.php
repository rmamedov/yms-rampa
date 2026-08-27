<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Denylist активних access-токенів за `jti` (AUTH-28, AUTH-32).
 *
 * Access-токен партнерського контуру живе 15 хвилин і є самодостатнім, тому
 * єдиний спосіб «вбити» його достроково — занести `jti` у спільне сховище,
 * яке перевіряється на КОЖЕН запит (шлюз → `/internal/v1/auth/verify`).
 *
 * Прод-реалізація — Redis із TTL, що дорівнює залишку життя токена: запис
 * зникає сам після `exp`, тому denylist не росте безмежно. У dev і тестах
 * працює реалізація в памʼяті (контур має підніматись без Redis).
 *
 * Контракт навмисно ідентичний однойменному інтерфейсу staff-контуру: обидва
 * identity-сервіси відповідають шлюзу за однаковими правилами.
 */
interface AccessTokenDenylist
{
    /** @param \DateTimeImmutable $expiresAt `exp` токена — визначає TTL запису */
    public function revoke(string $jti, \DateTimeImmutable $expiresAt): void;

    public function isRevoked(string $jti): bool;
}
