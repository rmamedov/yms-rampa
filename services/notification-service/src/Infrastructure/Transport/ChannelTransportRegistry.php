<?php

declare(strict_types=1);

namespace App\Infrastructure\Transport;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Transport\NotificationTransport;
use App\Domain\Transport\TransportException;
use App\Domain\Transport\TransportRegistry;

/**
 * Реєстр транспортів за каналом.
 *
 * Порядок має значення: перший транспорт, який заявив підтримку каналу,
 * і буде використаний. Це дає змогу підмінити реальний провайдер
 * на NullTransport через конфіг, не змінюючи коду (NOT-01).
 */
final class ChannelTransportRegistry implements TransportRegistry
{
    /** @var array<string, NotificationTransport> */
    private array $resolved = [];

    /** @var list<NotificationTransport> */
    private readonly array $transports;

    /** @param iterable<NotificationTransport> $transports */
    public function __construct(iterable $transports)
    {
        $this->transports = $transports instanceof \Traversable
            ? iterator_to_array($transports, false)
            : array_values($transports);
    }

    public function for(NotificationChannel $channel): NotificationTransport
    {
        if (isset($this->resolved[$channel->value])) {
            return $this->resolved[$channel->value];
        }

        foreach ($this->transports as $transport) {
            if ($transport->supports($channel)) {
                return $this->resolved[$channel->value] = $transport;
            }
        }

        throw new TransportException(
            \sprintf('Для каналу %s не налаштовано жодного транспорту.', $channel->label()),
            retryable: false,
        );
    }

    public function has(NotificationChannel $channel): bool
    {
        foreach ($this->transports as $transport) {
            if ($transport->supports($channel)) {
                return true;
            }
        }

        return false;
    }
}
