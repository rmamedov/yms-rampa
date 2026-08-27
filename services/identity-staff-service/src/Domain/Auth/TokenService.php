<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\Exception\RefreshReusedException;
use App\Domain\Auth\Exception\TokenExpiredException;
use App\Domain\Identity\Contour;
use App\Domain\Identity\StaffUser;
use App\Domain\Shared\Clock;
use App\Domain\Shared\Uuid;

/**
 * Видача, перевірка, ротація і відкликання токенів staff-контуру
 * (розділ 3.4: AUTH-30, AUTH-31, AUTH-32; AUTH-02/AUTH-03 щодо ізоляції контурів).
 */
final readonly class TokenService
{
    /**
     * Таблиця 3.4, контур staff.
     */
    public const int ACCESS_TTL_SECONDS = 900;          // 15 хвилин
    public const int REFRESH_TTL_SECONDS = 2_592_000;   // 30 днів

    public function __construct(
        private TokenSigner $signer,
        private RefreshTokenRepository $refreshTokens,
        private AccessTokenDenylist $denylist,
        private Clock $clock,
        private Contour $contour = Contour::Staff,
        private int $accessTtlSeconds = self::ACCESS_TTL_SECONDS,
        private int $refreshTtlSeconds = self::REFRESH_TTL_SECONDS,
    ) {
    }

    /**
     * AUTH-11: видача пари access (15 хв) + refresh (30 днів).
     * RBAC-26: клейми беруться з поточного стану користувача, тому refresh
     * після зміни ролі чи скоупа видає токен уже з новими значеннями.
     *
     * @param string|null $sessionId наявний sid при ротації; null — нова сесія
     */
    public function issueFor(
        StaffUser $user,
        ?string $sessionId = null,
        ?string $userAgent = null,
        ?string $ip = null,
    ): IssuedTokens {
        $now = $this->clock->now();
        $sid = $sessionId ?? Uuid::v4();

        $accessClaims = $this->buildClaims($user, $sid, TokenType::Access, $now, $this->accessTtlSeconds);
        $refreshClaims = $this->buildClaims($user, $sid, TokenType::Refresh, $now, $this->refreshTtlSeconds);

        $accessToken = $this->signer->sign($accessClaims->toArray());
        $refreshToken = $this->signer->sign($refreshClaims->toArray());

        // AUTH-30: зберігаємо лише SHA-256-хеш refresh-токена
        $this->refreshTokens->save(new RefreshTokenRecord(
            id: Uuid::v4(),
            userId: $user->id(),
            sessionId: $sid,
            tokenHash: RefreshTokenRecord::hash($refreshToken),
            issuedAt: $now,
            expiresAt: $refreshClaims->expiresAt,
            revokedAt: null,
            userAgent: $userAgent,
            ip: $ip,
        ));

        return new IssuedTokens(
            accessToken: $accessToken,
            accessExpiresAt: $accessClaims->expiresAt,
            refreshToken: $refreshToken,
            refreshExpiresAt: $refreshClaims->expiresAt,
            sessionId: $sid,
            accessJti: $accessClaims->jti,
        );
    }

    /**
     * Перевірка access-токена на кожному запиті (AUTH-03, RBAC-20):
     * підпис ключем staff-контуру, iss, aud, contour, тип токена, denylist за jti.
     *
     * Токен partner-контуру не проходить перевірку підпису → 401 AUTH_TOKEN_INVALID (AUTH-02).
     */
    public function verifyAccessToken(string $token): TokenClaims
    {
        $claims = $this->verify($token, TokenType::Access);

        if ($this->denylist->isRevoked($claims->jti)) {
            // AUTH-28: критичне пониження прав / деактивація
            throw new InvalidTokenException('jti у denylist');
        }

        return $claims;
    }

    /**
     * AUTH-31: ротація. Кожне використання refresh-токена видає нову пару
     * і позначає використаний refresh погашеним. Повторне використання вже
     * погашеного refresh відкликає весь ланцюжок sid → AUTH_REFRESH_REUSED.
     */
    public function consumeRefreshToken(string $refreshToken): TokenClaims
    {
        $claims = $this->verify($refreshToken, TokenType::Refresh);
        $now = $this->clock->now();

        $record = $this->refreshTokens->findByHash(RefreshTokenRecord::hash($refreshToken));

        if (null === $record) {
            throw new InvalidTokenException('refresh-токен невідомий сховищу');
        }

        if ($record->isRevoked()) {
            // Детекція крадіжки: гасимо весь ланцюжок сесії
            $this->refreshTokens->revokeSession($record->sessionId, $now);

            throw new RefreshReusedException();
        }

        if ($record->isExpiredAt($now)) {
            throw new TokenExpiredException();
        }

        $this->refreshTokens->save($record->revoked($now));

        return $claims;
    }

    /**
     * AUTH-32: logout відкликає refresh поточної сесії.
     */
    public function revokeSessionByRefreshToken(string $refreshToken): void
    {
        $now = $this->clock->now();
        $record = $this->refreshTokens->findByHash(RefreshTokenRecord::hash($refreshToken));

        if (null === $record) {
            // Ідемпотентність logout: невідомий токен не вважається помилкою
            return;
        }

        $this->refreshTokens->revokeSession($record->sessionId, $now);
    }

    /**
     * AUTH-32: «вийти з усіх пристроїв», зміна пароля, деактивація акаунта.
     */
    public function revokeAllSessions(string $userId, ?string $exceptSessionId = null): int
    {
        return $this->refreshTokens->revokeAllForUser($userId, $this->clock->now(), $exceptSessionId);
    }

    /**
     * Власник refresh-токена за його SHA-256-хешем (AUTH-30) — потрібен для
     * «вийти з усіх пристроїв», коли access-токен уже недоступний.
     */
    public function ownerOfRefreshHash(string $tokenHash): ?string
    {
        return $this->refreshTokens->findByHash($tokenHash)?->userId;
    }

    public function revokeSession(string $sessionId): int
    {
        return $this->refreshTokens->revokeSession($sessionId, $this->clock->now());
    }

    /**
     * AUTH-28/AUTH-17: занесення jti активного access-токена в denylist
     * з TTL = залишок життя токена.
     */
    public function denyAccessToken(TokenClaims $claims): void
    {
        $this->denylist->revoke($claims->jti, $claims->expiresAt);
    }

    public function accessTtlSeconds(): int
    {
        return $this->accessTtlSeconds;
    }

    public function refreshTtlSeconds(): int
    {
        return $this->refreshTtlSeconds;
    }

    private function verify(string $token, TokenType $expectedType): TokenClaims
    {
        if ('' === trim($token)) {
            throw new InvalidTokenException('порожній токен');
        }

        $claims = TokenClaims::fromArray($this->signer->verify($token));

        // AUTH-02: токен чужого контуру — помилка автентифікації
        if ($claims->contour !== $this->contour) {
            throw new InvalidTokenException(\sprintf(
                'контур токена "%s" не збігається з контуром сервісу "%s"',
                $claims->contour->value,
                $this->contour->value,
            ));
        }

        // AUTH-03: iss/aud перевіряються при кожному запиті
        if ($claims->issuer !== $this->contour->issuer() || $claims->audience !== $this->contour->audience()) {
            throw new InvalidTokenException('невідповідність iss/aud контуру');
        }

        if ($claims->type !== $expectedType) {
            throw new InvalidTokenException(\sprintf(
                'очікувався токен типу "%s", отримано "%s"',
                $expectedType->value,
                $claims->type->value,
            ));
        }

        return $claims;
    }

    private function buildClaims(
        StaffUser $user,
        string $sessionId,
        TokenType $type,
        \DateTimeImmutable $now,
        int $ttlSeconds,
    ): TokenClaims {
        return new TokenClaims(
            subject: $user->id(),
            role: $user->role(),
            contour: $this->contour,
            storeIds: $user->storeIds(),
            sessionId: $sessionId,
            jti: Uuid::v4(),
            type: $type,
            issuer: $this->contour->issuer(),
            audience: $this->contour->audience(),
            issuedAt: $now,
            expiresAt: $now->modify(\sprintf('+%d seconds', $ttlSeconds)),
        );
    }
}
