<?php

declare(strict_types=1);

namespace App\Tests\Domain\Password;

use App\Domain\Password\PasswordPolicy;
use App\Domain\Password\WeakPasswordException;
use App\Infrastructure\Security\Argon2idPasswordHasher;
use App\Infrastructure\Security\ArrayPasswordDenylist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Політика паролів staff-контуру, розділ 3.2.2 (AUTH-13).
 *
 * Мінімальна довжина 12, склад «велика + мала + цифра», заборона збігу
 * з email/імʼям, denylist поширених паролів, історія останніх 5.
 */
#[CoversClass(PasswordPolicy::class)]
#[CoversClass(WeakPasswordException::class)]
final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;
    private Argon2idPasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, threads: 1);
        $this->policy = new PasswordPolicy($this->hasher, new ArrayPasswordDenylist());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPasswordProvider(): array
    {
        return [
            'закоротка (11 символів)' => ['Rampa2026aB', 'мінімальна довжина'],
            'без великої літери' => ['rampa2026staff', 'велика літера'],
            'без малої літери' => ['RAMPA2026STAFF', 'мала літера'],
            'без цифри' => ['RampaStaffKyiv', 'цифра'],
            'збіг з email' => ['Ivan.Petrenko2026', 'email'],
            'зі словника поширених' => ['Password1234', 'поширений'],
            'повтори символів' => ['Aaaaaaaaaa1b', 'повтор'],
            'повторюваний блок' => ['Ab1Ab1Ab1Ab1', 'повтор'],
        ];
    }

    #[DataProvider('invalidPasswordProvider')]
    public function testRejectsPasswordViolatingPolicy(string $password, string $expectedHint): void
    {
        $violations = $this->policy->validate($password, 'ivan.petrenko@silpo.ua', 'Іван Петренко');

        self::assertNotSame([], $violations, \sprintf('Пароль "%s" мав бути відхилений.', $password));
        self::assertTrue(
            (bool) array_filter($violations, static fn (string $v): bool => str_contains($v, $expectedHint)),
            \sprintf(
                'Серед порушень очікувався текст із "%s", отримано: %s',
                $expectedHint,
                implode(' | ', $violations),
            ),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validPasswordProvider(): array
    {
        return [
            'типовий надійний' => ['Rampa!Staff2026'],
            'довгий з символами' => ['Sil-po#Yard-2026-Kyiv'],
            'кирилиця з цифрою' => ['Розвантаження2026Ok'],
        ];
    }

    #[DataProvider('validPasswordProvider')]
    public function testAcceptsCompliantPassword(string $password): void
    {
        self::assertSame([], $this->policy->validate($password, 'ivan.petrenko@silpo.ua', 'Іван Петренко'));
    }

    /**
     * AUTH-13: пароль довжиною рівно 12 символів проходить, 11 — ні.
     */
    public function testMinimumLengthBoundary(): void
    {
        self::assertSame(12, $this->policy->minLength());

        self::assertSame([], $this->policy->validate('Kyiv2026Ramp'));       // 12 символів
        self::assertNotSame([], $this->policy->validate('Kyiv2026Ram'));     // 11 символів
    }

    /**
     * AUTH-13: не повторювати останні 5 паролів.
     */
    public function testRejectsPasswordFromHistory(): void
    {
        $history = array_map(
            fn (string $password): string => $this->hasher->hash($password),
            ['Rampa!Staff2021', 'Rampa!Staff2022', 'Rampa!Staff2023', 'Rampa!Staff2024', 'Rampa!Staff2025'],
        );

        $violations = $this->policy->validate('Rampa!Staff2023', 'ivan@silpo.ua', 'Іван', $history);
        self::assertNotSame([], $violations);
        self::assertStringContainsString('останні 5 паролів', implode(' ', $violations));

        // Шостий за давністю пароль уже поза історією
        $historyWithSixth = [...$history, $this->hasher->hash('Rampa!Staff2020')];
        self::assertSame([], $this->policy->validate('Rampa!Staff2020', 'ivan@silpo.ua', 'Іван', $historyWithSixth));
    }

    /**
     * AUTH-13: відповідь містить ПЕРЕЛІК порушених правил, кожне окремим текстом.
     */
    public function testWeakPasswordExceptionCarriesAllViolations(): void
    {
        try {
            $this->policy->assertValid('qwerty', 'ivan@silpo.ua', 'Іван');
            self::fail('Очікувалася WeakPasswordException.');
        } catch (WeakPasswordException $exception) {
            self::assertSame('AUTH_WEAK_PASSWORD', $exception->errorCode());
            self::assertSame(422, $exception->httpStatus());
            self::assertGreaterThanOrEqual(3, \count($exception->violations()));
            self::assertSame($exception->violations(), $exception->context()['violations']);
            self::assertStringStartsWith('Пароль не відповідає вимогам безпеки:', $exception->userMessage());
        }
    }

    public function testDenylistIsCaseInsensitive(): void
    {
        $denylist = new ArrayPasswordDenylist(['MegaSecret2026']);

        self::assertTrue($denylist->contains('megasecret2026'));
        self::assertTrue($denylist->contains('  MEGASECRET2026  '));
        self::assertFalse($denylist->contains('MegaSecret2027'));
        self::assertGreaterThan(0, $denylist->size());
    }
}
