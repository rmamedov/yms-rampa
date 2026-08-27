<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Хешування паролів (AUTH-60): argon2id, параметри не нижче
 * memory 64 MB / time 3 / parallelism 4, з автоматичним rehash при логіні
 * після посилення параметрів.
 */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    /** Перевірка має бути стійкою до timing-атак. */
    public function verify(string $hash, string $plainPassword): bool;

    /** Чи потрібен перерахунок хеша під поточні параметри (AUTH-60). */
    public function needsRehash(string $hash): bool;
}
