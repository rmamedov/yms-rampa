<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Опис однієї підстановки шаблону (NOT-08).
 *
 * - required=true  — відсутнє або порожнє значення є помилкою рендерингу;
 * - required=false — порожнє значення замінюється на `fallback`
 *   (наприклад, порожній orderId рендериться як «без номера»);
 * - prefix         — додається перед НЕпорожнім значенням
 *   (наприклад, необовʼязковий коментар відмови рендериться як «, коментар»);
 * - sensitive      — значення ніколи не потрапляє в журнали і не персиститься
 *   після відправки (NOT-15, пароль водія).
 */
final readonly class TemplateVariableSpec
{
    public function __construct(
        public string $name,
        public bool $required = true,
        public string $fallback = '',
        public string $prefix = '',
        public bool $sensitive = false,
    ) {
    }

    public static function required(string $name, bool $sensitive = false): self
    {
        return new self(name: $name, required: true, sensitive: $sensitive);
    }

    public static function optional(string $name, string $fallback = '', string $prefix = ''): self
    {
        return new self(name: $name, required: false, fallback: $fallback, prefix: $prefix);
    }
}
