<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\PhoneNormalizer;
use App\Domain\Shared\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Нормалізація телефонів (SUP-DRV-02): будь-який формат вводу мусить давати
 * рівно один канонічний рядок +380XXXXXXXXX, інакше глобальну унікальність
 * телефону-логіна (DATA-17) можна обійти переформатуванням.
 */
#[CoversClass(PhoneNormalizer::class)]
final class PhoneNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validFormats(): iterable
    {
        yield 'канонічний E.164' => ['+380671112233'];
        yield 'без плюса' => ['380671112233'];
        yield 'національний з нулем' => ['0671112233'];
        yield 'абонентський без коду країни' => ['671112233'];
        yield 'міжнародний префікс 00' => ['00380671112233'];
        yield 'з пробілами' => ['+380 67 111 22 33'];
        yield 'з дефісами' => ['+380-67-111-22-33'];
        yield 'з дужками' => ['+38 (067) 111-22-33'];
        yield 'національний з пробілами' => ['067 111 22 33'];
        yield 'національний з дефісами' => ['067-111-22-33'];
        yield 'змішані роздільники' => [' 38 (067) 111 22-33 '];
        yield 'з крапками' => ['067.111.22.33'];
    }

    #[DataProvider('validFormats')]
    public function testNormalizesEveryInputFormatToTheSameCanonicalNumber(string $raw): void
    {
        self::assertSame('+380671112233', PhoneNormalizer::normalize($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFormats(): iterable
    {
        yield 'порожній рядок' => [''];
        yield 'лише пробіли' => ['   '];
        yield 'закоротко' => ['06711122'];
        yield 'задовго' => ['+3806711122334'];
        yield 'чужа країна' => ['+48671112233'];
        yield 'старий формат з вісімкою' => ['80671112233'];
        yield 'неіснуючий код оператора' => ['0111112233'];
        yield 'літери' => ['+38067АБВГД33'];
        yield 'самі роздільники' => ['---'];
    }

    #[DataProvider('invalidFormats')]
    public function testRejectsInputThatCannotBeReducedToUkrainianNumber(string $raw): void
    {
        $this->expectException(ValidationException::class);

        PhoneNormalizer::normalize($raw);
    }

    public function testInvalidPhoneCarriesMachineReadableErrorCode(): void
    {
        try {
            PhoneNormalizer::normalize('12345');
            self::fail('Очікувався ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('PHONE_INVALID', $e->errorCode());
            self::assertSame(422, $e->httpStatus());
            self::assertStringContainsString('+380XXXXXXXXX', $e->getMessage());
        }
    }

    public function testEmptyPhoneReportsSeparateErrorCode(): void
    {
        try {
            PhoneNormalizer::normalize('  ');
            self::fail('Очікувався ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('PHONE_REQUIRED', $e->errorCode());
        }
    }

    public function testOptionalPhoneTreatsNullAndBlankAsAbsent(): void
    {
        self::assertNull(PhoneNormalizer::normalizeOptional(null));
        self::assertNull(PhoneNormalizer::normalizeOptional('   '));
        self::assertSame('+380501234567', PhoneNormalizer::normalizeOptional('050 123 45 67'));
    }

    public function testIsValidMirrorsNormalizeWithoutThrowing(): void
    {
        self::assertTrue(PhoneNormalizer::isValid('067 111 22 33'));
        self::assertFalse(PhoneNormalizer::isValid('+1 202 555 0143'));
    }

    /**
     * Різні представлення того самого номера мають збігатися бітово —
     * саме на цьому тримається unique-індекс DATA-17.
     */
    public function testDifferentRepresentationsCollapseToOneKey(): void
    {
        $variants = ['+380671112233', '380671112233', '0671112233', '+38 (067) 111-22-33'];
        $normalized = array_unique(array_map(PhoneNormalizer::normalize(...), $variants));

        self::assertCount(1, $normalized);
    }
}
