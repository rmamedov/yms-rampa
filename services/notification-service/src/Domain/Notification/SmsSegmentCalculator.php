<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Розрахунок кількості SMS-сегментів (NOT-07).
 *
 * Кирилиця не входить у GSM-7, тому провайдер кодує повідомлення в UCS-2:
 * 70 символів в односегментному SMS і 67 символів у кожному сегменті
 * склеєного (concatenated) повідомлення — 3 символи зʼїдає UDH-заголовок.
 * Для суто латинського тексту діють звичні 160 / 153.
 *
 * NOT-07: допускається до 3 сегментів, типовий текст має вкладатися у 2.
 */
final class SmsSegmentCalculator
{
    public const int MAX_SEGMENTS = 3;

    private const int UCS2_SINGLE = 70;
    private const int UCS2_MULTIPART = 67;
    private const int GSM7_SINGLE = 160;
    private const int GSM7_MULTIPART = 153;

    /**
     * Базовий алфавіт GSM-7 разом із розширеннями. Будь-який символ поза цим
     * набором (зокрема вся кирилиця, «—», «№», лапки-ялинки) переводить
     * повідомлення в UCS-2.
     */
    private const string GSM7_ALPHABET = '@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€';

    /** Довжина повідомлення в символах (не в байтах). */
    public function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    public function isUnicode(string $text): bool
    {
        static $alphabet = null;
        if (null === $alphabet) {
            $alphabet = array_flip(mb_str_split(self::GSM7_ALPHABET, 1, 'UTF-8'));
            $alphabet["\n"] = true;
            $alphabet["\r"] = true;
        }

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if (!isset($alphabet[$char])) {
                return true;
            }
        }

        return false;
    }

    public function segments(string $text): int
    {
        $length = $this->length($text);
        if (0 === $length) {
            return 0;
        }

        $unicode = $this->isUnicode($text);
        $single = $unicode ? self::UCS2_SINGLE : self::GSM7_SINGLE;

        if ($length <= $single) {
            return 1;
        }

        $perPart = $unicode ? self::UCS2_MULTIPART : self::GSM7_MULTIPART;

        return (int) ceil($length / $perPart);
    }

    /** Максимально допустима кількість символів у межах NOT-07 (3 сегменти). */
    public function maxLength(string $text): int
    {
        return $this->isUnicode($text)
            ? self::UCS2_MULTIPART * self::MAX_SEGMENTS
            : self::GSM7_MULTIPART * self::MAX_SEGMENTS;
    }

    /** Чи вкладається текст у ліміт NOT-07. */
    public function fitsLimit(string $text): bool
    {
        return $this->segments($text) <= self::MAX_SEGMENTS;
    }
}
