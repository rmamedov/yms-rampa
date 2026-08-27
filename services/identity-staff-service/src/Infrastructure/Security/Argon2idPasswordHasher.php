<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Password\PasswordHasher;

/**
 * AUTH-60: argon2id через native password_hash() PHP.
 *
 * Параметри не нижче: memory 64 MB, time cost 3, parallelism 4;
 * конфігуровані, з автоматичним rehash при логіні після посилення.
 * MD5/SHA-x/bcrypt для нових паролів заборонені.
 *
 * AUTH-61: відкритий пароль ніколи не логується цим класом.
 */
final readonly class Argon2idPasswordHasher implements PasswordHasher
{
    public const int MIN_MEMORY_COST = 65536; // 64 MB у КіБ
    public const int MIN_TIME_COST = 3;
    public const int MIN_THREADS = 4;

    /**
     * @param int $memoryCost памʼять у КіБ
     */
    public function __construct(
        private int $memoryCost = self::MIN_MEMORY_COST,
        private int $timeCost = self::MIN_TIME_COST,
        private int $threads = self::MIN_THREADS,
    ) {
        if (!\defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException(
                'PHP зібрано без підтримки argon2id — вимога AUTH-60 не може бути виконана.',
            );
        }
    }

    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, \PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        if ('' === $hash) {
            return false;
        }

        return password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, \PASSWORD_ARGON2ID, $this->options());
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return [
            'memory_cost' => $this->memoryCost,
            'time_cost' => $this->timeCost,
            'threads' => $this->threads,
        ];
    }
}
