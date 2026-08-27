<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\Argon2idPasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-60: argon2id, параметри не нижче memory 64 MB / time 3 / parallelism 4,
 * автоматичний rehash при логіні після посилення параметрів.
 */
#[CoversClass(Argon2idPasswordHasher::class)]
final class Argon2idPasswordHasherTest extends TestCase
{
    public function testHashesWithArgon2idAndVerifies(): void
    {
        $hasher = new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, threads: 1);
        $hash = $hasher->hash('Rampa!Staff2026');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('Rampa!Staff2026', $hash));
        self::assertFalse($hasher->verify('Rampa!Staff2027', $hash));
        self::assertFalse($hasher->verify('Rampa!Staff2026', ''));
    }

    public function testSaltMakesHashesUnique(): void
    {
        $hasher = new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, threads: 1);

        self::assertNotSame($hasher->hash('Rampa!Staff2026'), $hasher->hash('Rampa!Staff2026'));
    }

    /**
     * AUTH-60: хеш, створений слабшими параметрами, вимагає rehash
     * прод-конфігурацією (64 MB / 3 / 4).
     */
    public function testWeakerParametersTriggerRehash(): void
    {
        $weak = new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, threads: 1);
        $production = new Argon2idPasswordHasher(
            memoryCost: Argon2idPasswordHasher::MIN_MEMORY_COST,
            timeCost: Argon2idPasswordHasher::MIN_TIME_COST,
            threads: Argon2idPasswordHasher::MIN_THREADS,
        );

        $weakHash = $weak->hash('Rampa!Staff2026');

        self::assertTrue($production->needsRehash($weakHash));
        self::assertFalse($weak->needsRehash($weakHash));

        // Прод-параметри відповідають мінімуму AUTH-60
        self::assertSame(65536, Argon2idPasswordHasher::MIN_MEMORY_COST);
        self::assertSame(3, Argon2idPasswordHasher::MIN_TIME_COST);
        self::assertSame(4, Argon2idPasswordHasher::MIN_THREADS);
    }

    /**
     * AUTH-60: bcrypt/SHA-x недопустимі — старий хеш обовʼязково перехешовується.
     */
    public function testLegacyBcryptHashNeedsRehash(): void
    {
        $hasher = new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, threads: 1);
        $bcrypt = password_hash('Rampa!Staff2026', \PASSWORD_BCRYPT);

        self::assertTrue($hasher->needsRehash($bcrypt));
    }
}
