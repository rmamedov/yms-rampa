<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Некоректний формат логіна при СТВОРЕННІ акаунта (AUTH-23, AUTH-29).
 *
 * Увага: у флоу логіну ця помилка НЕ використовується — там будь-який
 * непридатний ввід перетворюється на AUTH_INVALID_CREDENTIALS, щоб не
 * розкривати правила нормалізації та існування акаунтів (AUTH-53).
 */
final class InvalidLoginFormatException extends AuthException
{
    public function __construct(string $detail)
    {
        parent::__construct($detail);
    }

    public static function phone(string $raw): self
    {
        return new self(\sprintf(
            'Невірний формат номера телефону «%s». Очікується український номер, наприклад 067 123 45 67 або +380671234567.',
            $raw,
        ));
    }

    public static function email(string $raw): self
    {
        return new self(\sprintf('Невірний формат email «%s».', $raw));
    }

    public function errorCode(): string
    {
        return 'PARTNER_LOGIN_INVALID';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Некоректний логін';
    }
}
