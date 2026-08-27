<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * TOTP за RFC 6238 (AUTH-15): крок 30 с, вікно ±1, 6 цифр, HMAC-SHA1.
 *
 * Чистий домен: без залежностей від Symfony та зовнішніх бібліотек.
 */
final readonly class TotpVerifier
{
    public const int STEP_SECONDS = 30;
    public const int DIGITS = 6;
    public const int WINDOW = 1;

    public function __construct(
        private int $stepSeconds = self::STEP_SECONDS,
        private int $digits = self::DIGITS,
        private int $window = self::WINDOW,
    ) {
    }

    /**
     * @param string $base32Secret секрет у Base32 (як у QR-коді)
     */
    public function verify(string $base32Secret, string $code, \DateTimeImmutable $now): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (1 !== preg_match('/^\d{'.$this->digits.'}$/', $code)) {
            return false;
        }

        $key = self::base32Decode($base32Secret);

        if ('' === $key) {
            return false;
        }

        $counter = intdiv($now->getTimestamp(), $this->stepSeconds);

        for ($offset = -$this->window; $offset <= $this->window; ++$offset) {
            if (hash_equals($this->generate($key, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Генерація коду — використовується тестами та підтвердженням увімкнення 2FA.
     */
    public function codeAt(string $base32Secret, \DateTimeImmutable $at, int $offset = 0): string
    {
        $key = self::base32Decode($base32Secret);
        $counter = intdiv($at->getTimestamp(), $this->stepSeconds) + $offset;

        return $this->generate($key, $counter);
    }

    /**
     * Генерація нового секрету для показу в QR-коді (AUTH-15).
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    private function generate(string $key, int $counter): string
    {
        $binaryCounter = pack('J', max(0, $counter));
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = \ord($hash[\strlen($hash) - 1]) & 0x0F;

        $truncated = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($truncated % (10 ** $this->digits)),
            $this->digits,
            '0',
            \STR_PAD_LEFT,
        );
    }

    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim(preg_replace('/\s+/', '', $secret) ?? '', '='));

        if ('' === $secret) {
            return '';
        }

        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if (false === $index) {
                return '';
            }

            $buffer = ($buffer << 5) | $index;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= \chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }

    private static function base32Encode(string $bytes): string
    {
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | \ord($byte);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result .= self::ALPHABET[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $result .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $result;
    }
}
