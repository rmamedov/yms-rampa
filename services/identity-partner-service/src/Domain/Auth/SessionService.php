<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\ClientType;
use App\Domain\Account\PartnerAccountRepository;
use App\Domain\Clock\Clock;
use App\Domain\Exception\AccountDisabledException;
use App\Domain\Exception\RefreshTokenReusedException;
use App\Domain\Exception\TokenExpiredException;
use App\Domain\Exception\TokenInvalidException;
use App\Domain\Security\SecretGenerator;
use App\Domain\Session\RefreshTokenRepository;

/**
 * Ротація та відкликання сесій: `/auth/refresh` і `/auth/logout` обох
 * префіксів партнерського контуру (AUTH-31, AUTH-32, AUTH-40, DRV-09).
 */
final readonly class SessionService
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private PartnerAccountRepository $accounts,
        private SessionFactory $sessions,
        private SecretGenerator $secrets,
        private Clock $clock,
    ) {
    }

    /**
     * AUTH-31: кожне використання refresh видає нову пару і гасить старий
     * токен. Повторне використання погашеного токена відкликає весь ланцюжок
     * `sid` і повертає AUTH_REFRESH_REUSED.
     *
     * @throws TokenInvalidException        невідомий або відкликаний токен
     * @throws RefreshTokenReusedException  детекція крадіжки
     * @throws TokenExpiredException        строк дії refresh вичерпано
     * @throws AccountDisabledException     акаунт деактивовано (AUTH-28)
     */
    public function refresh(
        ClientType $client,
        string $refreshToken,
        ?string $ip = null,
        ?string $userAgent = null,
    ): AuthResult {
        $now = $this->clock->now();
        $stored = $this->refreshTokens->findByHash($this->secrets->hashToken($refreshToken));

        if (null === $stored) {
            throw new TokenInvalidException('refresh token not found');
        }

        if ($stored->isRedeemed()) {
            $this->refreshTokens->revokeChain($stored->sid, $now);

            throw new RefreshTokenReusedException();
        }

        $account = $this->accounts->findById($stored->accountId);

        if (null === $account) {
            $this->refreshTokens->revokeChain($stored->sid, $now);

            throw new TokenInvalidException('account behind refresh token is gone');
        }

        // AUTH-28: деактивований акаунт має отримати саме AUTH_ACCOUNT_DISABLED,
        // навіть якщо його токени вже відкликані масовою операцією.
        if (!$account->isActive()) {
            $this->refreshTokens->revokeAllForAccount($account->id, $now);

            throw new AccountDisabledException();
        }

        if ($stored->isRevoked()) {
            throw new TokenInvalidException('refresh token revoked');
        }

        if ($stored->isExpired($now)) {
            throw new TokenExpiredException();
        }

        if (!$client->allowsRole($account->role)) {
            throw new TokenInvalidException('refresh issued for another client contour');
        }

        $stored->redeem($now);
        $this->refreshTokens->save($stored);

        // DRV-07: «ковзне» вікно — тривалість сесії успадковується від
        // початкового логіну (90 днів для водія з «Запамʼятати мене»).
        return $this->sessions->issue(
            account: $account,
            sid: $stored->sid,
            refreshTtlSeconds: $stored->ttlSeconds,
            ip: $ip,
            userAgent: $userAgent,
        );
    }

    /**
     * AUTH-32 / DRV-09: logout відкликає refresh поточної сесії.
     * Ідемпотентний: невідомий токен не є помилкою (клієнт уже вийшов).
     */
    public function logout(string $refreshToken): void
    {
        $stored = $this->refreshTokens->findByHash($this->secrets->hashToken($refreshToken));

        if (null === $stored) {
            return;
        }

        $this->refreshTokens->revokeChain($stored->sid, $this->clock->now());
    }

    /** «Вийти з усіх пристроїв» та наслідок перегенерації пароля (AUTH-25, AUTH-32). */
    public function logoutAll(string $accountId): void
    {
        $this->refreshTokens->revokeAllForAccount($accountId, $this->clock->now());
    }
}
