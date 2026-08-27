<?php

declare(strict_types=1);

namespace App\Infrastructure\Transport;

use App\Domain\Exception\NotImplementedException;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Transport\NotificationTransport;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\TransportReceipt;

/**
 * Канал Viber — заділ на фазу 2 (NOT-01).
 *
 * Клас навмисно існує і зареєстрований, щоб модель каналів була повною,
 * але будь-яка спроба відправки завершується NotImplementedException.
 */
final class ViberTransport implements NotificationTransport
{
    public function supports(NotificationChannel $channel): bool
    {
        return NotificationChannel::Viber === $channel;
    }

    public function send(OutgoingMessage $message): TransportReceipt
    {
        throw new NotImplementedException(
            'Канал Viber заплановано на фазу 2 і він недоступний у MVP (NOT-01).',
        );
    }

    public function name(): string
    {
        return 'viber';
    }
}
