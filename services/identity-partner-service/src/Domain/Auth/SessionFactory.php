<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\PartnerAccount;
use App\Domain\Clock\Clock;
use App\Domain\Security\SecretGenerator;
use App\Domain\Session\RefreshToken;
use App\Domain\Session\RefreshTokenRepository;
use App\Domain\Token\TokenIssuer;

/**
 * Створення пари access + refresh у межах одного ланцюжка сесії `sid`.
 *
 * Спільна частина логіну (3.5) і ротації (AUTH-31): refresh зберігається лише
 * хешем (AUTH-30), тривалість сесії переноситься між ротаціями, щоб водій із
 * «Запамʼятати мене» не вводив пароль частіше ніж раз на 90 днів (DRV-07).
 */
final readonly class SessionFactory
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private TokenIssuer $tokenIssuer,
        private SecretGenerator $secrets,
        private Clock $clock,
    ) {
    }

    public function issue(
        PartnerAccount $account,
        string $sid,
        int $refreshTtlSeconds,
        ?string $ip = null,
        ?string $userAgent = null,
    ): AuthResult {
        $now = $this->clock->now();
        $plainRefresh = $this->secrets->newOpaqueToken();

        $refresh = new RefreshToken(
            id: $this->secrets->newId(),
            sid: $sid,
            accountId: $account->id,
            tokenHash: $this->secrets->hashToken($plainRefresh),
            userType: $account->role->userType(),
            issuedAt: $now,
            expiresAt: $now->modify(\sprintf('+%d seconds', $refreshTtlSeconds)),
            ttlSeconds: $refreshTtlSeconds,
            userAgent: $userAgent,
            ip: $ip,
        );

        $this->refreshTokens->save($refresh);

        $access = $this->tokenIssuer->issueAccessToken($account, $sid);

        return new AuthResult(
            accessToken: $access->token,
            accessExpiresAt: $access->expiresAt,
            accessExpiresIn: $access->expiresInSeconds(),
            refreshToken: $plainRefresh,
            refreshExpiresAt: $refresh->expiresAt,
            sid: $sid,
            profile: $account->profile(),
        );
    }

    public function newSessionId(): string
    {
        return $this->secrets->newId();
    }
}
