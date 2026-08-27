<?php

declare(strict_types=1);

namespace App\Domain\Branch;

/**
 * YMS-статус філії (10.2.1, діаграма станів 5.3.1).
 *
 * not_configured → active → paused ⇄ active; будь-який стан → archived (термінальний).
 */
enum YmsStatus: string
{
    case NotConfigured = 'not_configured';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Не налаштовано',
            self::Active => 'Активний',
            self::Paused => 'На паузі',
            self::Archived => 'Архівний',
        };
    }

    /**
     * Дозволені ручні переходи. archived — термінальний стан:
     * зникла з MCP філія повертається до роботи лише через повторне заведення (INT-09).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NotConfigured => [self::Active, self::Archived],
            self::Active => [self::Paused, self::Archived],
            self::Paused => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $target === $this || \in_array($target, $this->allowedTransitions(), true);
    }

    /** Чи бачать філію постачальники в supplier-web за самим лише статусом (STC-04, STC-06). */
    public function allowsSupplierVisibility(): bool
    {
        return self::Active === $this;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
