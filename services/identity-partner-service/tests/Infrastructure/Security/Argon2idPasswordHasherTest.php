<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\Argon2idPasswordHasher;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\TestCase;

/** AUTH-60: argon2id, memory 64 MB / time 3 / parallelism 4, автоматичний rehash. */
final class Argon2idPasswordHasherTest extends TestCase
{
    public function testHashesWithArgon2id(): void
    {
        $hash = AuthTestEnvironment::fastHasher()->hash('Rmp7dK2xTq');

        self::assertStringStartsWith('$argon2id$', $hash);
    }

    public function testVerifiesCorrectPasswordAndRejectsWrongOne(): void
    {
        $hasher = AuthTestEnvironment::fastHasher();
        $hash = $hasher->hash('Rmp7dK2xTq');

        self::assertTrue($hasher->verify($hash, 'Rmp7dK2xTq'));
        self::assertFalse($hasher->verify($hash, 'rmp7dk2xtq'));
        self::assertFalse($hasher->verify($hash, ''));
    }

    public function testPlainPasswordIsNotRecoverableFromHash(): void
    {
        // AUTH-61: пароль ніде не зберігається у відкритому вигляді.
        $hash = AuthTestEnvironment::fastHasher()->hash('Rmp7dK2xTq');

        self::assertStringNotContainsString('Rmp7dK2xTq', $hash);
    }

    public function testSaltMakesEqualPasswordsProduceDifferentHashes(): void
    {
        $hasher = AuthTestEnvironment::fastHasher();

        self::assertNotSame($hasher->hash('Rmp7dK2xTq'), $hasher->hash('Rmp7dK2xTq'));
    }

    public function testEmptyHashIsNeverAccepted(): void
    {
        self::assertFalse(AuthTestEnvironment::fastHasher()->verify('', 'будь-що'));
    }

    public function testWeakerParametersTriggerRehashUnderProductionSettings(): void
    {
        // AUTH-60: після посилення параметрів хеш має перераховуватись при логіні.
        $weakHash = (new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, parallelism: 1))->hash('Rmp7dK2xTq');
        $production = new Argon2idPasswordHasher();

        self::assertTrue($production->needsRehash($weakHash));
        self::assertFalse($production->needsRehash($production->hash('Rmp7dK2xTq')));
    }

    public function testProductionDefaultsMatchSpecification(): void
    {
        self::assertSame(65536, Argon2idPasswordHasher::DEFAULT_MEMORY_COST);
        self::assertSame(3, Argon2idPasswordHasher::DEFAULT_TIME_COST);
        self::assertSame(4, Argon2idPasswordHasher::DEFAULT_PARALLELISM);
    }
}
