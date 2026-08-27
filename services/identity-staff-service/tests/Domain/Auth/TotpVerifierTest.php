<?php

declare(strict_types=1);

namespace App\Tests\Domain\Auth;

use App\Domain\Auth\TotpVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-15: TOTP за RFC 6238 — крок 30 с, вікно ±1.
 */
#[CoversClass(TotpVerifier::class)]
final class TotpVerifierTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function testAcceptsCurrentCodeAndNeighbouringSteps(): void
    {
        $verifier = new TotpVerifier();
        $now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');

        self::assertTrue($verifier->verify(self::SECRET, $verifier->codeAt(self::SECRET, $now), $now));
        // вікно ±1 крок (30 с)
        self::assertTrue($verifier->verify(self::SECRET, $verifier->codeAt(self::SECRET, $now, -1), $now));
        self::assertTrue($verifier->verify(self::SECRET, $verifier->codeAt(self::SECRET, $now, 1), $now));
    }

    public function testRejectsCodeOutsideWindow(): void
    {
        $verifier = new TotpVerifier();
        $now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');

        self::assertFalse($verifier->verify(self::SECRET, $verifier->codeAt(self::SECRET, $now, 3), $now));
        self::assertFalse($verifier->verify(self::SECRET, $verifier->codeAt(self::SECRET, $now, -3), $now));
    }

    public function testRejectsMalformedCodeAndSecret(): void
    {
        $verifier = new TotpVerifier();
        $now = new \DateTimeImmutable('2026-08-27T09:00:00+00:00');

        self::assertFalse($verifier->verify(self::SECRET, '12345', $now));
        self::assertFalse($verifier->verify(self::SECRET, 'abcdef', $now));
        self::assertFalse($verifier->verify('', '123456', $now));
        self::assertFalse($verifier->verify('не-base32!', '123456', $now));
    }

    public function testGeneratedCodeIsSixDigits(): void
    {
        $verifier = new TotpVerifier();
        $secret = TotpVerifier::generateSecret();
        $code = $verifier->codeAt($secret, new \DateTimeImmutable('2026-08-27T09:00:00+00:00'));

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    /**
     * Контрольний вектор RFC 6238 (секрет «12345678901234567890» у Base32,
     * час 59 с від епохи, HMAC-SHA1, 6 цифр) — код 287082.
     */
    public function testMatchesRfc6238ReferenceVector(): void
    {
        $verifier = new TotpVerifier();

        self::assertSame(
            '287082',
            $verifier->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', new \DateTimeImmutable('@59')),
        );
    }
}
