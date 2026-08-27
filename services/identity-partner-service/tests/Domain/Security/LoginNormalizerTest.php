<?php

declare(strict_types=1);

namespace App\Tests\Domain\Security;

use App\Domain\Account\ClientType;
use App\Domain\Account\PartnerRole;
use App\Domain\Exception\InvalidLoginFormatException;
use App\Domain\Security\LoginNormalizer;
use App\Domain\Security\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-21 / AUTH-23: логін постачальника — email у нижньому регістрі,
 * логін водія — телефон E.164.
 */
final class LoginNormalizerTest extends TestCase
{
    private LoginNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new LoginNormalizer(new PhoneNormalizer());
    }

    public function testSupplierEmailIsLowercasedAndTrimmed(): void
    {
        self::assertSame(
            'sales@postachalnyk.ua',
            $this->normalizer->normalizeForRole(PartnerRole::SupplierAdmin, '  Sales@Postachalnyk.UA  '),
        );
    }

    public function testDriverLoginIsNormalizedAsPhoneEvenWhenTypedNationally(): void
    {
        self::assertSame(
            '+380671234567',
            $this->normalizer->normalizeForRole(PartnerRole::Driver, '067 123 45 67'),
        );
    }

    public function testDriverWebClientAlwaysNormalizesPhone(): void
    {
        self::assertSame(
            '+380509876543',
            $this->normalizer->normalizeForClient(ClientType::DriverWeb, '(050) 987-65-43'),
        );
    }

    public function testSupplierWebClientRejectsPhoneShapedLogin(): void
    {
        // Постачальник входить лише за email — телефон у supplier-web не логін.
        self::assertNull($this->normalizer->tryNormalizeForClient(ClientType::SupplierWeb, '0671234567'));
    }

    public function testDriverWebClientRejectsEmailShapedLogin(): void
    {
        self::assertNull($this->normalizer->tryNormalizeForClient(ClientType::DriverWeb, 'driver@postachalnyk.ua'));
    }

    public function testMalformedEmailIsRejectedOnAccountCreation(): void
    {
        $this->expectException(InvalidLoginFormatException::class);

        $this->normalizer->normalizeEmail('не-email');
    }

    public function testMaskingHidesLoginInAuditLog(): void
    {
        // AUTH-52: у журнал іде масковане значення.
        $maskedPhone = $this->normalizer->mask('+380671234567');
        $maskedEmail = $this->normalizer->mask('sales@postachalnyk.ua');

        self::assertStringNotContainsString('1234567', $maskedPhone);
        self::assertStringStartsWith('+380', $maskedPhone);
        self::assertStringEndsWith('4567', $maskedPhone);
        self::assertStringStartsWith('sa', $maskedEmail);
        self::assertStringEndsWith('@postachalnyk.ua', $maskedEmail);
        self::assertStringNotContainsString('sales@', $maskedEmail);
    }
}
