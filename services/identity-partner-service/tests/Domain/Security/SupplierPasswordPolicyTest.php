<?php

declare(strict_types=1);

namespace App\Tests\Domain\Security;

use App\Domain\Exception\WeakPasswordException;
use App\Domain\Security\SupplierPasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-21: пароль постачальника — мінімум 10 символів, літери + цифри,
 * denylist поширених паролів. AUTH-13: перелік порушених правил українською.
 */
final class SupplierPasswordPolicyTest extends TestCase
{
    public function testAcceptsCompliantPassword(): void
    {
        $policy = new SupplierPasswordPolicy();
        $policy->assertValid('Postachalnyk2026', 'sales@postachalnyk.ua');

        self::assertSame([], $policy->violations('Postachalnyk2026'));
    }

    public function testRejectsShortPassword(): void
    {
        $violations = (new SupplierPasswordPolicy())->violations('Abc12345');

        self::assertContains('Пароль має містити щонайменше 10 символів.', $violations);
    }

    public function testRejectsPasswordWithoutDigits(): void
    {
        $violations = (new SupplierPasswordPolicy())->violations('PostachalnykUA');

        self::assertContains('Пароль має містити щонайменше одну цифру.', $violations);
    }

    public function testRejectsPasswordWithoutLetters(): void
    {
        $violations = (new SupplierPasswordPolicy())->violations('1234567890123');

        self::assertContains('Пароль має містити щонайменше одну літеру.', $violations);
    }

    public function testRejectsDenylistedPassword(): void
    {
        $violations = (new SupplierPasswordPolicy())->violations('password123');

        self::assertContains('Пароль занадто поширений — оберіть інший.', $violations);
    }

    public function testRejectsPasswordEqualToLogin(): void
    {
        $violations = (new SupplierPasswordPolicy())->violations('Sales@Postachalnyk.UA', 'sales@postachalnyk.ua');

        self::assertContains('Пароль не може збігатися з логіном.', $violations);
    }

    public function testThrowsWeakPasswordWithCanonicalCodeAndViolations(): void
    {
        $policy = new SupplierPasswordPolicy();

        try {
            $policy->assertValid('короткий');
            self::fail('Очікувався WeakPasswordException.');
        } catch (WeakPasswordException $exception) {
            self::assertSame('AUTH_WEAK_PASSWORD', $exception->errorCode());
            self::assertSame(422, $exception->httpStatus());
            self::assertNotEmpty($exception->violations);
            self::assertSame($exception->violations, $exception->extensions()['violations']);
        }
    }
}
