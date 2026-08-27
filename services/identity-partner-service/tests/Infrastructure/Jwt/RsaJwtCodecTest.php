<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Jwt;

use App\Domain\Account\Contour;
use App\Domain\Account\PartnerRole;
use App\Domain\Exception\TokenExpiredException;
use App\Domain\Exception\TokenInvalidException;
use App\Domain\Security\SecretGenerator;
use App\Infrastructure\Jwt\RsaJwtCodec;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Розділ 3.4 (клейми, TTL 15 хв), AUTH-03 (перевірка підпису, iss, aud, exp).
 */
final class RsaJwtCodecTest extends TestCase
{
    private AuthTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new AuthTestEnvironment();
    }

    public function testIssuedTokenCarriesAllMandatoryClaims(): void
    {
        $driver = $this->env->givenDriver();
        $issued = $this->env->codec->issueAccessToken($driver, 'sid-1');
        $claims = $this->env->codec->verifyAccessToken($issued->token);

        self::assertSame($driver->id, $claims->subject);
        self::assertSame(PartnerRole::Driver, $claims->role);
        self::assertSame(Contour::Partner, $claims->contour);
        self::assertSame('sp-01', $claims->supplierId);
        self::assertSame('du-99', $claims->driverId);
        self::assertSame('sid-1', $claims->sid);
        self::assertSame($issued->jti, $claims->jti);
        self::assertSame('yms-partner', $claims->issuer);
        self::assertSame('yms-partner-api', $claims->audience);
        self::assertSame('partner', $claims->raw['contour']);
        self::assertSame('sp-01', $claims->raw['scope']['supplierId']);
    }

    public function testRoleClaimIsSingularNotAnArray(): void
    {
        // Розділ 3.4: клейм `role` — рівно одна роль, масив ролей не використовується.
        $supplier = $this->env->givenSupplier();
        $issued = $this->env->codec->issueAccessToken($supplier, 'sid-2');
        $claims = $this->env->codec->verifyAccessToken($issued->token);

        self::assertIsString($claims->raw['role']);
        self::assertSame('supplier_admin', $claims->raw['role']);
        self::assertArrayNotHasKey('roles', $claims->raw);
    }

    public function testAccessTokenLivesFifteenMinutes(): void
    {
        $driver = $this->env->givenDriver();
        $issued = $this->env->codec->issueAccessToken($driver, 'sid-3');

        self::assertSame(900, $issued->expiresInSeconds());
        self::assertSame(900, $issued->expiresAt->getTimestamp() - $issued->issuedAt->getTimestamp());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $driver = $this->env->givenDriver();
        $issued = $this->env->codec->issueAccessToken($driver, 'sid-4');

        $this->env->clock->advance('+16 minutes');

        $this->expectException(TokenExpiredException::class);
        $this->env->codec->verifyAccessToken($issued->token);
    }

    public function testExpiredTokenCarriesCanonicalErrorCode(): void
    {
        $driver = $this->env->givenDriver();
        $issued = $this->env->codec->issueAccessToken($driver, 'sid-5');
        $this->env->clock->advance('+1 hour');

        try {
            $this->env->codec->verifyAccessToken($issued->token);
            self::fail('Очікувався TokenExpiredException.');
        } catch (TokenExpiredException $exception) {
            self::assertSame('AUTH_TOKEN_EXPIRED', $exception->errorCode());
            self::assertSame(401, $exception->httpStatus());
            self::assertSame('Сесія завершилась. Увійдіть повторно.', $exception->getMessage());
        }
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $driver = $this->env->givenDriver();
        $issued = $this->env->codec->issueAccessToken($driver, 'sid-6');

        [$header, $payload, $signature] = explode('.', $issued->token);
        $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        self::assertIsArray($decoded);
        $decoded['role'] = PartnerRole::SupplierAdmin->value;
        $forgedPayload = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');

        $this->expectException(TokenInvalidException::class);
        $this->env->codec->verifyAccessToken($header.'.'.$forgedPayload.'.'.$signature);
    }

    public function testAlgNoneTokenIsRejected(): void
    {
        $driver = $this->env->givenDriver();
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        $forged = $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode([
            'sub' => $driver->id,
            'role' => 'driver',
            'contour' => 'partner',
            'supplierId' => 'sp-01',
            'sid' => 'sid-7',
            'jti' => 'jti-7',
            'iss' => 'yms-partner',
            'aud' => 'yms-partner-api',
            'iat' => $this->env->clock->now()->getTimestamp(),
            'exp' => $this->env->clock->now()->getTimestamp() + 900,
        ]).'.';

        $this->expectException(TokenInvalidException::class);
        $this->env->codec->verifyAccessToken($forged);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->env->codec->verifyAccessToken('це-не-jwt');
    }

    public function testTokenWithForeignIssuerIsRejectedEvenWithValidSignature(): void
    {
        // AUTH-03: підпис той самий (ті самі ключі), але iss/aud інші —
        // токен усе одно має бути відхилений.
        $foreignIssuerCodec = new RsaJwtCodec(
            keys: AuthTestEnvironment::partnerKeys(),
            clock: $this->env->clock,
            secrets: new SecretGenerator(),
            issuer: 'yms-partner-sandbox',
            audience: 'yms-partner-api',
            contour: Contour::Partner,
        );

        $driver = $this->env->givenDriver();
        $issued = $foreignIssuerCodec->issueAccessToken($driver, 'sid-8');

        $this->expectException(TokenInvalidException::class);
        $this->env->codec->verifyAccessToken($issued->token);
    }

    public function testTokenWithForeignAudienceIsRejected(): void
    {
        $foreignAudienceCodec = new RsaJwtCodec(
            keys: AuthTestEnvironment::partnerKeys(),
            clock: $this->env->clock,
            secrets: new SecretGenerator(),
            issuer: 'yms-partner',
            audience: 'yms-partner-internal',
            contour: Contour::Partner,
        );

        $driver = $this->env->givenDriver();
        $issued = $foreignAudienceCodec->issueAccessToken($driver, 'sid-9');

        $this->expectException(TokenInvalidException::class);
        $this->env->codec->verifyAccessToken($issued->token);
    }

    public function testKeyIdIsPublishedInHeaderForRotation(): void
    {
        // AUTH-64: kid у заголовку дозволяє ротацію з періодом перекриття.
        $driver = $this->env->givenDriver();
        $issued = $this->env->codec->issueAccessToken($driver, 'sid-10');
        $header = json_decode((string) base64_decode(strtr(explode('.', $issued->token)[0], '-_', '+/'), true), true);

        self::assertIsArray($header);
        self::assertSame('RS256', $header['alg']);
        self::assertSame('partner-test-1', $header['kid']);
    }
}
