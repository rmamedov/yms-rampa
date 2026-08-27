<?php

declare(strict_types=1);

namespace App\Tests\Domain\Vehicle;

use App\Domain\Shared\ValidationException;
use App\Domain\Vehicle\PlateNumberNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Нормалізація держномера (SUP-BOOK-02, SUP-BOOK-03): верхній регістр,
 * без пробілів і роздільників, довжина 4–12, латиниця й кирилиця.
 */
#[CoversClass(PlateNumberNormalizer::class)]
final class PlateNumberNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizationCases(): iterable
    {
        yield 'нижній регістр латиницею' => ['aa1234bb', 'AA1234BB'];
        yield 'пробіли всередині' => ['AA 1234 BB', 'AA1234BB'];
        yield 'пробіли по краях' => ['  AA1234BB  ', 'AA1234BB'];
        yield 'дефіси' => ['aa-1234-bb', 'AA1234BB'];
        yield 'кирилиця' => ['аа1234вв', 'АА1234ВВ'];
        yield 'змішаний регістр' => ['Aa1234Bb', 'AA1234BB'];
        yield 'мінімальна довжина' => ['ab12', 'AB12'];
        yield 'максимальна довжина' => ['ab1234567890', 'AB1234567890'];
        yield 'лише цифри' => ['12345', '12345'];
        yield 'українські літери Є І Ї Ґ' => ['єіїґ12', 'ЄІЇҐ12'];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalizesPlateNumber(string $raw, string $expected): void
    {
        self::assertSame($expected, PlateNumberNormalizer::normalize($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCases(): iterable
    {
        yield 'порожній' => [''];
        yield 'лише пробіли' => ['   '];
        yield 'закороткий' => ['AB1'];
        yield 'задовгий' => ['AB12345678901'];
        yield 'спецсимволи' => ['AA#1234'];
        yield 'слеш' => ['AA/1234'];
        yield 'самі роздільники' => ['- - -'];
    }

    #[DataProvider('invalidCases')]
    public function testRejectsInvalidPlateNumbers(string $raw): void
    {
        $this->expectException(ValidationException::class);

        PlateNumberNormalizer::normalize($raw);
    }

    public function testTooShortPlateReportsLengthErrorCode(): void
    {
        try {
            PlateNumberNormalizer::normalize('AB1');
            self::fail('Очікувався ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('VEHICLE_PLATE_INVALID', $e->errorCode());
            self::assertStringContainsString('від 4 до 12', $e->getMessage());
        }
    }

    public function testEmptyPlateReportsRequiredErrorCode(): void
    {
        try {
            PlateNumberNormalizer::normalize('   ');
            self::fail('Очікувався ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('VEHICLE_PLATE_REQUIRED', $e->errorCode());
        }
    }

    public function testCyrillicLengthIsCountedInCharactersNotBytes(): void
    {
        // «АА1234ВВ» — 8 символів, але 12 байтів у UTF-8.
        self::assertSame('АА1234ВВ', PlateNumberNormalizer::normalize('аа1234вв'));
        self::assertTrue(PlateNumberNormalizer::isValid('ААВВ'));
    }
}
