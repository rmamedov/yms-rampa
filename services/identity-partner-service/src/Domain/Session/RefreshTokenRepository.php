<?php

declare(strict_types=1);

namespace App\Domain\Session;

/**
 * Репозиторій refresh-токенів партнерського контуру (10.6).
 *
 * AUTH-30: пошук виконується виключно за SHA-256-хешем токена.
 */
interface RefreshTokenRepository
{
    public function save(RefreshToken $token): void;

    public function findByHash(string $tokenHash): ?RefreshToken;

    /**
     * Усі токени ланцюжка сесії.
     *
     * @return list<RefreshToken>
     */
    public function findBySid(string $sid): array;

    /** Відкликання всього ланцюжка сесії (logout, детекція reuse — AUTH-31/32). */
    public function revokeChain(string $sid, \DateTimeImmutable $at): void;

    /**
     * «Вийти з усіх пристроїв», зміна/перегенерація пароля, деактивація
     * акаунта (AUTH-25, AUTH-28, AUTH-32).
     */
    public function revokeAllForAccount(string $accountId, \DateTimeImmutable $at): void;

    /**
     * Активні (не відкликані й не погашені) токени акаунта.
     *
     * @return list<RefreshToken>
     */
    public function findActiveForAccount(string $accountId, \DateTimeImmutable $now): array;
}
