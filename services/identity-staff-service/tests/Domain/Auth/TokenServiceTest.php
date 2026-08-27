<?php

declare(strict_types=1);

namespace App\Tests\Domain\Auth;

use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\Exception\RefreshReusedException;
use App\Domain\Auth\Exception\TokenExpiredException;
use App\Domain\Auth\RefreshTokenRecord;
use App\Domain\Auth\TokenService;
use App\Domain\Auth\TokenType;
use App\Domain\Identity\Contour;
use App\Domain\Identity\Role;
use App\Tests\Support\AuthContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Токени staff-контуру: клейми 3.4, ізоляція контурів (AUTH-02/AUTH-03),
 * ротація і детекція крадіжки (AUTH-31), відкликання (AUTH-32).
 */
#[CoversClass(TokenService::class)]
final class TokenServiceTest extends TestCase
{
    private AuthContext $context;

    protected function setUp(): void
    {
        $this->context = new AuthContext();
    }

    /**
     * AUTH-11 + розділ 3.4: обовʼязкові клейми access-токена,
     * зокрема `role` В ОДНИНІ (RBAC-04) та contour=staff.
     */
    public function testAccessTokenCarriesCanonicalClaims(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A', 'B']);
        $tokens = $this->context->tokens->issueFor($user, null, 'PHPUnit', '10.0.0.1');

        $claims = $this->context->tokens->verifyAccessToken($tokens->accessToken);

        self::assertSame($user->id(), $claims->subject);
        self::assertSame(Role::StoreManager, $claims->role);
        self::assertSame(Contour::Staff, $claims->contour);
        self::assertSame(['A', 'B'], $claims->storeIds);
        self::assertSame('yms-staff', $claims->issuer);
        self::assertSame('yms-staff-api', $claims->audience);
        self::assertSame(TokenType::Access, $claims->type);
        self::assertSame($tokens->sessionId, $claims->sessionId);
        self::assertNotSame('', $claims->jti);

        // Клейм role — рядок, а не масив
        $payload = $this->context->staffSigner->verify($tokens->accessToken);
        self::assertIsString($payload['role']);
        self::assertSame('staff', $payload['contour']);
    }

    /**
     * Таблиця 3.4: access 15 хв, refresh 30 днів.
     */
    public function testTokenLifetimesMatchSpecification(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $now = $this->context->clock->now();

        $tokens = $this->context->tokens->issueFor($user);

        self::assertSame(900, $tokens->accessExpiresAt->getTimestamp() - $now->getTimestamp());
        self::assertSame(2_592_000, $tokens->refreshExpiresAt->getTimestamp() - $now->getTimestamp());
        self::assertSame(900, $tokens->accessTtlSeconds($now));
    }

    /**
     * AUTH-30/DATA-19: у сховищі лежить ЛИШЕ SHA-256-хеш refresh-токена.
     */
    public function testOnlyRefreshTokenHashIsStored(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $tokens = $this->context->tokens->issueFor($user, null, 'PHPUnit', '10.0.0.1');

        $records = $this->context->refreshTokens->all();
        self::assertCount(1, $records);

        $record = $records[0];
        self::assertSame(hash('sha256', $tokens->refreshToken), $record->tokenHash);
        self::assertNotSame($tokens->refreshToken, $record->tokenHash);
        self::assertSame($user->id(), $record->userId);
        self::assertSame('PHPUnit', $record->userAgent);
        self::assertSame('10.0.0.1', $record->ip);
        self::assertFalse($record->isRevoked());
    }

    /**
     * AUTH-02 / RBAC-AC-02: токен partner-контуру на staff-API —
     * 401 AUTH_TOKEN_INVALID (підпис не валідується ключем контуру).
     */
    public function testPartnerTokenIsRejectedByStaffService(): void
    {
        $partnerToken = $this->context->partnerAccessToken();

        try {
            $this->context->tokens->verifyAccessToken($partnerToken);
            self::fail('Очікувалася відмова AUTH_TOKEN_INVALID.');
        } catch (InvalidTokenException $exception) {
            self::assertSame('AUTH_TOKEN_INVALID', $exception->errorCode());
            self::assertSame(401, $exception->httpStatus());
            self::assertSame('Помилка автентифікації. Увійдіть повторно.', $exception->userMessage());
        }
    }

    /**
     * AUTH-03: навіть підписаний staff-ключем токен із клеймами partner-контуру
     * відхиляється — перевіряються contour, iss і aud.
     */
    public function testTokenWithPartnerClaimsSignedByStaffKeyIsRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->context->tokens->verifyAccessToken($this->context->partnerClaimsSignedByStaffKey());
    }

    public function testTamperedTokenIsRejected(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $tokens = $this->context->tokens->issueFor($user);

        $this->expectException(InvalidTokenException::class);
        $this->context->tokens->verifyAccessToken($tokens->accessToken.'x');
    }

    /**
     * Refresh-токен не приймається там, де очікується access (клейм `typ`).
     */
    public function testRefreshTokenCannotBeUsedAsAccessToken(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $tokens = $this->context->tokens->issueFor($user);

        $this->expectException(InvalidTokenException::class);
        $this->context->tokens->verifyAccessToken($tokens->refreshToken);
    }

    /**
     * Таблиця 3.7: прострочений access-токен — 401 AUTH_TOKEN_EXPIRED.
     */
    public function testExpiredAccessTokenIsRejected(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $tokens = $this->context->tokens->issueFor($user);

        $this->context->clock->advance('+16 minutes');

        $this->expectException(TokenExpiredException::class);
        $this->context->tokens->verifyAccessToken($tokens->accessToken);
    }

    /**
     * AUTH-31: ротація — нова пара, старий refresh погашено, sid збережено.
     */
    public function testRefreshRotationIssuesNewPairAndBurnsTheOldOne(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $first = $this->context->tokens->issueFor($user);

        $this->context->clock->advance('+10 minutes');
        $claims = $this->context->tokens->consumeRefreshToken($first->refreshToken);
        $second = $this->context->tokens->issueFor($user, $claims->sessionId);

        self::assertSame($first->sessionId, $second->sessionId, 'sid зберігається в межах ланцюжка');
        self::assertNotSame($first->refreshToken, $second->refreshToken);
        self::assertNotSame($first->accessToken, $second->accessToken);

        $burned = $this->context->refreshTokens->findByHash(RefreshTokenRecord::hash($first->refreshToken));
        self::assertNotNull($burned);
        self::assertTrue($burned->isRevoked(), 'Використаний refresh має бути погашений');

        // Новий refresh працює
        $this->context->tokens->consumeRefreshToken($second->refreshToken);
    }

    /**
     * AUTH-31 / критерій 3.9.4: повторне використання погашеного refresh
     * відкликає ВЕСЬ ланцюжок sid і повертає AUTH_REFRESH_REUSED.
     */
    public function testReusedRefreshTokenRevokesWholeSessionChain(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $first = $this->context->tokens->issueFor($user);

        $claims = $this->context->tokens->consumeRefreshToken($first->refreshToken);
        $second = $this->context->tokens->issueFor($user, $claims->sessionId);

        try {
            // «Крадій» намагається використати вже погашений токен
            $this->context->tokens->consumeRefreshToken($first->refreshToken);
            self::fail('Очікувалася відмова AUTH_REFRESH_REUSED.');
        } catch (RefreshReusedException $exception) {
            self::assertSame('AUTH_REFRESH_REUSED', $exception->errorCode());
            self::assertSame(401, $exception->httpStatus());
        }

        // Увесь ланцюжок сесії загашено — свіжий токен теж недійсний
        $latest = $this->context->refreshTokens->findByHash(RefreshTokenRecord::hash($second->refreshToken));
        self::assertNotNull($latest);
        self::assertTrue($latest->isRevoked());

        $this->expectException(RefreshReusedException::class);
        $this->context->tokens->consumeRefreshToken($second->refreshToken);
    }

    public function testUnknownRefreshTokenIsRejected(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $tokens = $this->context->tokens->issueFor($user);

        // Токен валідний за підписом, але сховищу невідомий (напр. після повного logout)
        $this->context->tokens->revokeSessionByRefreshToken($tokens->refreshToken);
        $foreign = new AuthContext();
        $foreignUser = $foreign->createUser('admin@silpo.ua', Role::SuperAdmin);
        $foreignTokens = $foreign->tokens->issueFor($foreignUser);

        $this->expectException(InvalidTokenException::class);
        $this->context->tokens->consumeRefreshToken($foreignTokens->refreshToken);
    }

    /**
     * AUTH-32: logout гасить сесію; повторний refresh неможливий.
     */
    public function testLogoutRevokesCurrentSession(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $tokens = $this->context->tokens->issueFor($user);

        $this->context->tokens->revokeSessionByRefreshToken($tokens->refreshToken);

        $this->expectException(RefreshReusedException::class);
        $this->context->tokens->consumeRefreshToken($tokens->refreshToken);
    }

    /**
     * AUTH-32: «вийти з усіх пристроїв» гасить усі sid, окрім явно збереженої сесії.
     */
    public function testRevokeAllSessionsKeepsOnlyExcludedSession(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);

        $desktop = $this->context->tokens->issueFor($user, null, 'desktop');
        $mobile = $this->context->tokens->issueFor($user, null, 'mobile');
        $tablet = $this->context->tokens->issueFor($user, null, 'tablet');

        $revoked = $this->context->tokens->revokeAllSessions($user->id(), $mobile->sessionId);
        self::assertSame(2, $revoked);

        $active = $this->context->refreshTokens->findActiveForUser($user->id(), $this->context->clock->now());
        self::assertCount(1, $active);
        self::assertSame($mobile->sessionId, $active[0]->sessionId);

        unset($desktop, $tablet);
    }

    /**
     * AUTH-28: jti у denylist — access-токен відхиляється до закінчення exp.
     */
    public function testDenylistedAccessTokenIsRejected(): void
    {
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin);
        $tokens = $this->context->tokens->issueFor($user);

        $claims = $this->context->tokens->verifyAccessToken($tokens->accessToken);
        $this->context->tokens->denyAccessToken($claims);

        self::assertTrue($this->context->denylist->isRevoked($claims->jti));

        $this->expectException(InvalidTokenException::class);
        $this->context->tokens->verifyAccessToken($tokens->accessToken);
    }

    public function testEmptyTokenIsRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->context->tokens->verifyAccessToken('   ');
    }
}
