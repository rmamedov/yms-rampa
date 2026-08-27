<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\SecurePasswordGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Генерація пароля водія (SUP-DRV-03): 12 символів, криптостійко,
 * без неоднозначних символів 0/O/1/l/I.
 */
#[CoversClass(SecurePasswordGenerator::class)]
final class SecurePasswordGeneratorTest extends TestCase
{
    private const SAMPLE_SIZE = 300;

    public function testPasswordHasExactlyTwelveCharacters(): void
    {
        $generator = new SecurePasswordGenerator();

        for ($i = 0; $i < 50; ++$i) {
            self::assertSame(12, \strlen($generator->generate()));
        }
    }

    /**
     * Пароль диктують по SMS і вводять на телефоні, тому символи,
     * які легко сплутати, заборонені повністю.
     */
    public function testPasswordNeverContainsAmbiguousCharacters(): void
    {
        $generator = new SecurePasswordGenerator();

        for ($i = 0; $i < self::SAMPLE_SIZE; ++$i) {
            $password = $generator->generate();

            foreach (SecurePasswordGenerator::AMBIGUOUS as $char) {
                self::assertStringNotContainsString(
                    $char,
                    $password,
                    \sprintf('Пароль «%s» містить неоднозначний символ «%s».', $password, $char),
                );
            }
        }
    }

    public function testPasswordUsesOnlyAllowedAlphabet(): void
    {
        $generator = new SecurePasswordGenerator();
        $allowed = SecurePasswordGenerator::UPPERCASE
            .SecurePasswordGenerator::LOWERCASE
            .SecurePasswordGenerator::DIGITS;

        for ($i = 0; $i < self::SAMPLE_SIZE; ++$i) {
            $password = $generator->generate();

            self::assertSame(
                '',
                trim($password, $allowed),
                \sprintf('Пароль «%s» містить символ поза дозволеним алфавітом.', $password),
            );
        }
    }

    public function testPasswordAlwaysMixesUppercaseLowercaseAndDigit(): void
    {
        $generator = new SecurePasswordGenerator();

        for ($i = 0; $i < self::SAMPLE_SIZE; ++$i) {
            $password = $generator->generate();

            self::assertMatchesRegularExpression('/[A-Z]/', $password);
            self::assertMatchesRegularExpression('/[a-z]/', $password);
            self::assertMatchesRegularExpression('/[0-9]/', $password);
        }
    }

    /**
     * Колізій на такій вибірці бути не може: алфавіт 58 символів,
     * довжина 12 — простір ~10^21. Повтор означав би зламане джерело
     * випадковості (напр. mt_rand із фіксованим seed).
     */
    public function testPasswordsDoNotRepeat(): void
    {
        $generator = new SecurePasswordGenerator();
        $passwords = [];

        for ($i = 0; $i < self::SAMPLE_SIZE; ++$i) {
            $passwords[] = $generator->generate();
        }

        self::assertCount(self::SAMPLE_SIZE, array_unique($passwords));
    }

    /**
     * Обов'язкові класи символів не повинні «прилипати» до початку рядка —
     * інакше перші три позиції були б передбачуваними.
     */
    public function testMandatoryCharacterClassesArePositionallyShuffled(): void
    {
        $generator = new SecurePasswordGenerator();
        $firstCharClasses = [];

        for ($i = 0; $i < self::SAMPLE_SIZE; ++$i) {
            $first = $generator->generate()[0];
            $firstCharClasses[match (true) {
                str_contains(SecurePasswordGenerator::UPPERCASE, $first) => 'upper',
                str_contains(SecurePasswordGenerator::LOWERCASE, $first) => 'lower',
                default => 'digit',
            }] = true;
        }

        self::assertCount(3, $firstCharClasses, 'Перша позиція має бути з різних класів символів.');
    }

    public function testCustomLengthIsRespectedAndFloored(): void
    {
        self::assertSame(16, \strlen((new SecurePasswordGenerator(16))->generate()));
        // Пароль коротший за три обов'язкові класи безглуздий — довжина піднімається до 4.
        self::assertSame(4, \strlen((new SecurePasswordGenerator(2))->generate()));
    }
}
