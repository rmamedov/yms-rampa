<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exception;

use App\Domain\Exception\DomainException;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;

/**
 * Шаблон не призначений для цього каналу (розділ 11.2.2).
 */
final class ChannelNotSupportedException extends DomainException
{
    public function __construct(NotificationTemplate $template, NotificationChannel $channel)
    {
        parent::__construct(
            \sprintf('Шаблон %s не розсилається каналом %s.', $template->code(), $channel->label()),
            'TEMPLATE_CHANNEL_NOT_SUPPORTED',
            422,
        );
    }
}
