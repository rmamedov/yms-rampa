<?php

declare(strict_types=1);

namespace App\Domain\Transport;

use App\Domain\Notification\NotificationChannel;

/**
 * Реєстр транспортів: за каналом віддає той, що його обслуговує.
 */
interface TransportRegistry
{
    /**
     * @throws TransportException якщо для каналу немає транспорту
     */
    public function for(NotificationChannel $channel): NotificationTransport;

    public function has(NotificationChannel $channel): bool;
}
