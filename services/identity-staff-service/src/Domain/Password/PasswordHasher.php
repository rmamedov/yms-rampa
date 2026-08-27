<?php

declare(strict_types=1);

namespace App\Domain\Password;

/**
 * Хешування паролів (AUTH-60): argon2id, параметри не нижче
 * memory 64 MB / time 3 / parallelism 4. MD5/SHA-x/bcrypt для нових паролів заборонені.
 */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hash): bool;

    /**
     * AUTH-60: автоматичний rehash при логіні після посилення параметрів.
     */
    public function needsRehash(string $hash): bool;
}
