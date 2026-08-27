<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Exception\ValidationException;

/**
 * Email як логін співробітника.
 *
 * AUTH-10: email нормалізується до нижнього регістру, обрізаються пробіли.
 * DATA (10.5): у колекції `staff_users` поле `email` — unique, lowercase.
 */
final readonly class Email implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $normalized = mb_strtolower(trim($raw));

        if ('' === $normalized) {
            throw new ValidationException('Вкажіть email.', ['Email не може бути порожнім']);
        }

        if (!filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Некоректний формат email.', ['Email має бути у форматі name@example.com']);
        }

        return new self($normalized);
    }

    /**
     * Локальна частина — використовується політикою паролів (AUTH-13:
     * заборона збігу пароля з email).
     */
    public function localPart(): string
    {
        $at = strrpos($this->value, '@');

        return false === $at ? $this->value : substr($this->value, 0, $at);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
