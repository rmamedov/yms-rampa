<?php

declare(strict_types=1);

namespace App\Tests\Domain\Security;

use App\Domain\Security\DriverPasswordGenerator;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-24: пароль водія — 10 символів з алфавіту без омоглифів
 * (виключені 0, O, 1, l, I), криптографічно стійкий генератор.
 */
final class DriverPasswordGeneratorTest extends TestCase
{
    public function testGeneratesTenCharacterPassword(): void
    {
        self::assertSame(10, \strlen((new DriverPasswordGenerator())->generate()));
    }

    public function testAlphabetHasNoHomoglyphs(): void
    {
        $generator = new DriverPasswordGenerator();
        $forbidden = ['0', 'O', '1', 'l', 'I'];

        // Перевіряємо і сам алфавіт, і велику вибірку згенерованих паролів.
        foreach ($forbidden as $character) {
            self::assertStringNotContainsString($character, DriverPasswordGenerator::ALPHABET);
        }

        for ($i = 0; $i < 200; ++$i) {
            $password = $generator->generate();

            foreach ($forbidden as $character) {
                self::assertStringNotContainsString($character, $password);
            }
        }
    }

    public function testPasswordsAreNotRepeated(): void
    {
        $generator = new DriverPasswordGenerator();
        $passwords = [];

        for ($i = 0; $i < 100; ++$i) {
            $passwords[] = $generator->generate();
        }

        self::assertCount(100, array_unique($passwords), 'Згенеровані паролі мають бути унікальними.');
    }

    public function testRejectsTooShortLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DriverPasswordGenerator())->generate(4);
    }
}
