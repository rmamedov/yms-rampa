<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Password\PasswordDenylist;

/**
 * Denylist поширених паролів (AUTH-13).
 *
 * У проді список підвантажується з файлу (≥ 100 тис. записів) або з Redis-set;
 * тут — базовий вбудований перелік плюс довільний зовнішній файл.
 * Порівняння нечутливе до регістру.
 */
final class ArrayPasswordDenylist implements PasswordDenylist
{
    /** @var array<string, true> */
    private array $index = [];

    /**
     * @param list<string> $passwords
     */
    public function __construct(array $passwords = [])
    {
        foreach ([...self::builtin(), ...$passwords] as $password) {
            $this->index[mb_strtolower($password)] = true;
        }
    }

    /**
     * Завантаження denylist з файлу (по одному паролю в рядку).
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            return new self();
        }

        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        return new self(false === $lines ? [] : array_values($lines));
    }

    public function contains(string $plainPassword): bool
    {
        return isset($this->index[mb_strtolower(trim($plainPassword))]);
    }

    public function size(): int
    {
        return \count($this->index);
    }

    /**
     * @return list<string>
     */
    private static function builtin(): array
    {
        return [
            'password', 'password1', 'password123', 'Password123', 'Password1234',
            'qwerty', 'qwerty123', 'Qwerty123456', 'qwertyuiop', '123456', '1234567890',
            'admin', 'admin123', 'Admin12345678', 'welcome', 'Welcome123456',
            'letmein', 'iloveyou', 'monkey', 'dragon', 'football', 'silpo', 'Silpo123456',
            'rampa', 'Rampa1234567', 'yms', 'Yms123456789',
        ];
    }
}
