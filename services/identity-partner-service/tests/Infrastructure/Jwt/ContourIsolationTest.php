<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Jwt;

use App\Domain\Account\Contour;
use App\Domain\Exception\TokenInvalidException;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Ізоляція контурів — AUTH-02, AUTH-03, AUTH-04 і зведений критерій 3.9.2.
 *
 * Staff-токен на partner-API і partner-токен на staff-API мають стабільно
 * повертати 401 AUTH_TOKEN_INVALID.
 */
final class ContourIsolationTest extends TestCase
{
    private AuthTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new AuthTestEnvironment();
    }

    public function testStaffTokenIsRejectedByPartnerCodec(): void
    {
        $driver = $this->env->givenDriver();
        $staffToken = $this->env->staffCodec()->issueAccessToken($driver, 'sid-staff');

        try {
            $this->env->codec->verifyAccessToken($staffToken->token);
            self::fail('Staff-токен не повинен прийматись partner-контуром.');
        } catch (TokenInvalidException $exception) {
            self::assertSame('AUTH_TOKEN_INVALID', $exception->errorCode());
            self::assertSame(401, $exception->httpStatus());
            self::assertSame('Помилка автентифікації. Увійдіть повторно.', $exception->getMessage());
        }
    }

    public function testPartnerTokenIsRejectedByStaffCodec(): void
    {
        $driver = $this->env->givenDriver();
        $partnerToken = $this->env->codec->issueAccessToken($driver, 'sid-partner');

        $this->expectException(TokenInvalidException::class);
        $this->env->staffCodec()->verifyAccessToken($partnerToken->token);
    }

    public function testContoursUseDifferentKeyPairs(): void
    {
        // AUTH-02: приватний ключ staff недоступний partner-контуру і навпаки.
        self::assertNotSame(
            AuthTestEnvironment::partnerKeys()->privateKeyPem,
            AuthTestEnvironment::staffKeys()->privateKeyPem,
        );
        self::assertNotSame(
            AuthTestEnvironment::partnerKeys()->keyId,
            AuthTestEnvironment::staffKeys()->keyId,
        );
    }

    public function testCanonicalIssuerAndAudiencePerContour(): void
    {
        // AUTH-03: staff — yms-staff / yms-staff-api; partner — yms-partner / yms-partner-api.
        self::assertSame('yms-staff', Contour::Staff->issuer());
        self::assertSame('yms-staff-api', Contour::Staff->audience());
        self::assertSame('yms-partner', Contour::Partner->issuer());
        self::assertSame('yms-partner-api', Contour::Partner->audience());
    }

    public function testPartnerTokenAlwaysDeclaresPartnerContour(): void
    {
        $supplier = $this->env->givenSupplier();
        $issued = $this->env->codec->issueAccessToken($supplier, 'sid-c');
        $claims = $this->env->codec->verifyAccessToken($issued->token);

        self::assertSame(Contour::Partner, $claims->contour);
        self::assertNotSame(Contour::Staff->value, $claims->raw['contour']);
    }
}
