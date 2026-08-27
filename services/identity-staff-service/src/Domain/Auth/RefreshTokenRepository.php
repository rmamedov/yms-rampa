<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Сховище refresh-токенів (колекція `refresh_tokens`, 10.5).
 */
interface RefreshTokenRepository
{
    public function save(RefreshTokenRecord $record): void;

    /**
     * AUTH-30: пошук виконується за SHA-256-хешем, самі токени не зберігаються.
     */
    public function findByHash(string $tokenHash): ?RefreshTokenRecord;

    /**
     * AUTH-31: детекція крадіжки — відкликання всього ланцюжка сесії.
     *
     * @return int кількість відкликаних записів
     */
    public function revokeSession(string $sessionId, \DateTimeImmutable $now): int;

    /**
     * AUTH-14 / AUTH-32: «вийти з усіх пристроїв», зміна пароля, деактивація.
     *
     * @param string|null $exceptSessionId сесія, яку слід зберегти (поточна)
     *
     * @return int кількість відкликаних записів
     */
    public function revokeAllForUser(string $userId, \DateTimeImmutable $now, ?string $exceptSessionId = null): int;

    /**
     * @return list<RefreshTokenRecord>
     */
    public function findActiveForUser(string $userId, \DateTimeImmutable $now): array;
}
