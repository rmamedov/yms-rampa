<?php

declare(strict_types=1);

namespace App\Domain\Password;

/**
 * Генератор тимчасових паролів для облікових записів, які заводить
 * адміністратор (розділ 4.7).
 *
 * Пароль показується РІВНО ОДИН РАЗ — у відповіді на створення акаунта або
 * на перегенерацію; у базі лишається лише argon2id-хеш (AUTH-61), тому
 * повторно показати його неможливо. Такий самий підхід у partner-service
 * для водіїв (SUP-DRV-03, SUP-DRV-04).
 */
interface PasswordGenerator
{
    /**
     * @param string|null $email    email майбутнього власника — щоб згенерований
     *                              пароль гарантовано не збігся з ним (AUTH-13)
     * @param string|null $fullName те саме для повного імені
     */
    public function generate(?string $email = null, ?string $fullName = null): string;
}
