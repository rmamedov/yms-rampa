<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Статус доставки сповіщення (NOT-03):
 * queued → sent → delivered / failed / expired.
 *
 * `Queued` — це стан «в черзі / очікує відправки» (pending у постановці задачі):
 * сповіщення збережене, але провайдер його ще не прийняв.
 */
enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Expired = 'expired';

    /** Термінальні статуси більше не змінюються і не ретраяться. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Delivered, self::Failed, self::Expired => true,
            self::Queued, self::Sent => false,
        };
    }

    /** Чи повідомлення успішно передано провайдеру. */
    public function isSuccessful(): bool
    {
        return self::Sent === $this || self::Delivered === $this;
    }
}
