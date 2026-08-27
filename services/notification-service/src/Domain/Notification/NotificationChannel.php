<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Канали доставки сповіщень (NOT-01).
 *
 * У scope MVP — лише SMS та e-mail. Канал Viber закладений у модель
 * як заділ; реалізація — фаза 2.
 */
enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Viber = 'viber';

    /** Чи входить канал у scope MVP (NOT-01). */
    public function isAvailableInMvp(): bool
    {
        return self::Viber !== $this;
    }

    /** Людська назва каналу для журналів і адмінки. */
    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Email => 'E-mail',
            self::Viber => 'Viber',
        };
    }

    /**
     * Резервний канал для критичних сповіщень (NOT-04):
     * якщо основний канал остаточно впав, критичне сповіщення
     * дублюється сюди — за умови, що адреса отримувача заповнена.
     */
    public function fallback(): ?self
    {
        return match ($this) {
            self::Sms => self::Email,
            self::Email => self::Sms,
            self::Viber => self::Sms,
        };
    }
}
