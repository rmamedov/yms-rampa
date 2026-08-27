<?php

declare(strict_types=1);

namespace App\Tests\Domain\Security;

use App\Domain\Exception\InvalidLoginFormatException;
use App\Domain\Security\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-23: логін водія зберігається строго як `+380XXXXXXXXX`; ввід у
 * форматах `0XX…`, `380…`, `80…`, з пробілами/дужками/дефісами нормалізується
 * автоматично.
 */
final class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PhoneNormalizer();
    }

    #[DataProvider('provideNormalizableInputs')]
    public function testNormalizesUkrainianPhonesToE164(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideNormalizableInputs(): iterable
    {
        yield 'вже канонічний E.164' => ['+380671234567', '+380671234567'];
        yield 'з кодом країни без плюса' => ['380671234567', '+380671234567'];
        yield 'національний з нулем' => ['0671234567', '+380671234567'];
        yield 'критерій приймання 3.3.2' => ['067 123 45 67', '+380671234567'];
        yield 'у дужках і з дефісами' => ['(067) 123-45-67', '+380671234567'];
        yield 'міжнародний з пробілами' => ['+38 (067) 123 45 67', '+380671234567'];
        yield 'старий формат 8-0XX' => ['80671234567', '+380671234567'];
        yield 'дев’ять цифр без нуля' => ['671234567', '+380671234567'];
        yield 'з крапками' => ['067.123.45.67', '+380671234567'];
        yield 'з ведучими та кінцевими пробілами' => ['   0671234567   ', '+380671234567'];
        yield 'з нерозривним пробілом' => ["067\u{00A0}123\u{00A0}45\u{00A0}67", '+380671234567'];
        yield 'інший оператор (050)' => ['050 987 65 43', '+380509876543'];
    }

    #[DataProvider('provideInvalidInputs')]
    public function testRejectsNonUkrainianOrMalformedPhones(string $raw): void
    {
        self::assertNull($this->normalizer->tryNormalize($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidInputs(): iterable
    {
        yield 'порожній рядок' => [''];
        yield 'лише пробіли' => ['   '];
        yield 'закоротко' => ['06712345'];
        yield 'задовго' => ['06712345678901'];
        yield 'російський номер із плюсом' => ['+79161234567'];
        yield 'польський номер із плюсом' => ['+48123456789'];
        yield 'email замість телефону' => ['driver@postachalnyk.ua'];
        yield 'літери в номері' => ['067ABC4567'];
        yield 'плюс без коду країни' => ['+0671234567'];
    }

    public function testThrowsUkrainianErrorForUnparsablePhone(): void
    {
        $this->expectException(InvalidLoginFormatException::class);
        $this->expectExceptionMessageMatches('/Невірний формат номера телефону/u');

        $this->normalizer->normalize('+79161234567');
    }

    public function testInvalidLoginFormatCarriesCanonicalCodeAndStatus(): void
    {
        $exception = InvalidLoginFormatException::phone('123');

        self::assertSame('PARTNER_LOGIN_INVALID', $exception->errorCode());
        self::assertSame(422, $exception->httpStatus());
    }

    public function testRecognisesCanonicalFormat(): void
    {
        self::assertTrue($this->normalizer->isCanonical('+380671234567'));
        self::assertFalse($this->normalizer->isCanonical('0671234567'));
        self::assertFalse($this->normalizer->isCanonical('+38067123456'));
    }

    public function testDifferentInputFormatsCollapseToTheSameLogin(): void
    {
        $variants = ['+380671234567', '380671234567', '0671234567', '067 123 45 67', '(067)123-45-67', '80671234567'];
        $normalized = array_map(fn (string $raw): string => $this->normalizer->normalize($raw), $variants);

        self::assertCount(1, array_unique($normalized), 'Усі формати вводу мають давати один і той самий логін водія.');
    }
}
