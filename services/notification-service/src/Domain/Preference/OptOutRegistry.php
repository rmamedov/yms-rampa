<?php

declare(strict_types=1);

namespace App\Domain\Preference;

use App\Domain\Notification\NotificationTemplate;

/**
 * Налаштування opt-out користувачів partner-контуру (NOT-05).
 *
 * Вимкнути можна лише некритичні сповіщення (нагадування). Перевірку
 * критичності робить сам домен — реєстр відповідає тільки за факт
 * відмови користувача.
 */
interface OptOutRegistry
{
    public function isOptedOut(string $recipientId, NotificationTemplate $template): bool;
}
