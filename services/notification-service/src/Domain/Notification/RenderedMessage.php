<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Готовий до відправки текст сповіщення.
 *
 * `subject` і `html` заповнюються лише для каналу e-mail.
 */
final readonly class RenderedMessage
{
    public function __construct(
        public NotificationTemplate $template,
        public NotificationChannel $channel,
        public string $text,
        public ?string $subject = null,
        public ?string $html = null,
    ) {
    }

    public function length(): int
    {
        return mb_strlen($this->text, 'UTF-8');
    }
}
