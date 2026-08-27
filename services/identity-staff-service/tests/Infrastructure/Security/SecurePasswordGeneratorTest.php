<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Domain\Password\PasswordPolicy;
use App\Infrastructure\Security\Argon2idPasswordHasher;
use App\Infrastructure\Security\ArrayPasswordDenylist;
use App\Infrastructure\Security\SecurePasswordGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Одноразові паролі для акаунтів, які заводить адміністратор (розділ 4.7).
 *
 * Головна вимога: генератор не має власних правил складності — усе, що він
 * видає, зобовʼязане проходити НАЯВНУ політику AUTH-13.
 */
#[CoversClass(SecurePasswordGenerator::class)]
final class SecurePasswordGeneratorTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy(
            new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, threads: 1),
            new ArrayPasswordDenylist(),
        );
    }

    public function testGeneratedPasswordsAlwaysSatisfyThePolicy(): void
    {
        $generator = new SecurePasswordGenerator($this->policy);

        for ($i = 0; $i < 200; ++$i) {
            $password = $generator->generate('olena@silpo.ua', 'Олена Іванова');

            self::assertSame([], $this->policy->validate($password, 'olena@silpo.ua', 'Олена Іванова'));
            self::assertSame(SecurePasswordGenerator::DEFAULT_LENGTH, mb_strlen($password));
        }
    }

    /**
     * Пароль диктують і переписують з екрана: сплутати 0/O і 1/l/I не можна.
     */
    public function testGeneratedPasswordsHaveNoAmbiguousCharacters(): void
    {
        $generator = new SecurePasswordGenerator($this->policy);

        for ($i = 0; $i < 100; ++$i) {
            $password = $generator->generate();

            foreach (['0', 'O', '1', 'l', 'I'] as $ambiguous) {
                self::assertStringNotContainsString($ambiguous, $password);
            }
        }
    }

    public function testGeneratedPasswordsDoNotRepeat(): void
    {
        $generator = new SecurePasswordGenerator($this->policy);

        $seen = [];
        for ($i = 0; $i < 100; ++$i) {
            $seen[] = $generator->generate();
        }

        self::assertCount(100, array_unique($seen));
    }

    /**
     * Довжина не може опуститися нижче мінімуму політики — інакше жоден
     * згенерований пароль не пройшов би валідацію.
     */
    public function testLengthNeverDropsBelowPolicyMinimum(): void
    {
        $generator = new SecurePasswordGenerator($this->policy, length: 4);

        self::assertSame($this->policy->minLength(), mb_strlen($generator->generate()));
    }
}
