<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Security\PasswordHasher;

/**
 * argon2id через native password hasher PHP (AUTH-60).
 *
 * Параметри за замовчуванням — мінімум із специфікації: memory 64 MB,
 * time cost 3, parallelism 4. Значення конфігуровані, а `needsRehash`
 * забезпечує автоматичний перерахунок хеша при логіні після посилення.
 */
final readonly class Argon2idPasswordHasher implements PasswordHasher
{
    /** memory_cost вимірюється в КіБ: 65536 КіБ = 64 МБ. */
    public const int DEFAULT_MEMORY_COST = 65536;

    public const int DEFAULT_TIME_COST = 3;

    public const int DEFAULT_PARALLELISM = 4;

    public function __construct(
        private int $memoryCost = self::DEFAULT_MEMORY_COST,
        private int $timeCost = self::DEFAULT_TIME_COST,
        private int $parallelism = self::DEFAULT_PARALLELISM,
    ) {
        if (!\defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException('PHP зібрано без підтримки argon2id — хешування паролів неможливе (AUTH-60).');
        }
    }

    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, \PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(string $hash, string $plainPassword): bool
    {
        // password_verify стійка до timing-атак; порожній хеш обробляємо
        // окремо, щоб не покладатись на поведінку розширення.
        if ('' === $hash) {
            return false;
        }

        return password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, \PASSWORD_ARGON2ID, $this->options());
    }

    /** @return array<string, int> */
    private function options(): array
    {
        return [
            'memory_cost' => $this->memoryCost,
            'time_cost' => $this->timeCost,
            'threads' => $this->parallelism,
        ];
    }
}
