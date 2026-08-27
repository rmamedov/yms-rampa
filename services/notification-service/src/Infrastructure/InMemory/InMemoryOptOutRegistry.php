<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Notification\NotificationTemplate;
use App\Domain\Preference\OptOutRegistry;

/**
 * Реєстр opt-out у памʼяті (NOT-05).
 *
 * Реальні налаштування зберігаються у профілі користувача partner-контуру;
 * тут — легка реалізація для тестів і dev-режиму.
 */
final class InMemoryOptOutRegistry implements OptOutRegistry
{
    /** @var array<string, true> ключ — «recipientId|templateCode» або «recipientId|*» */
    private array $optedOut = [];

    public function isOptedOut(string $recipientId, NotificationTemplate $template): bool
    {
        return isset($this->optedOut[$recipientId.'|'.$template->code()])
            || isset($this->optedOut[$recipientId.'|*']);
    }

    /** Вимкнути всі некритичні сповіщення для користувача. */
    public function optOutAll(string $recipientId): void
    {
        $this->optedOut[$recipientId.'|*'] = true;
    }

    public function optOut(string $recipientId, NotificationTemplate $template): void
    {
        $this->optedOut[$recipientId.'|'.$template->code()] = true;
    }

    public function optIn(string $recipientId, NotificationTemplate $template): void
    {
        unset($this->optedOut[$recipientId.'|'.$template->code()]);
    }

    public function clear(): void
    {
        $this->optedOut = [];
    }
}
