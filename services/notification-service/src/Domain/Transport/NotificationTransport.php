<?php

declare(strict_types=1);

namespace App\Domain\Transport;

use App\Domain\Notification\NotificationChannel;

/**
 * Канальний транспорт доставки (NOT-01).
 *
 * Конкретні реалізації: SMS (TurboSMS/eSputnik через SmsProviderInterface),
 * e-mail (Symfony Mailer), Viber (заглушка, фаза 2), Null та InMemory.
 */
interface NotificationTransport
{
    public function supports(NotificationChannel $channel): bool;

    /**
     * @throws TransportException при збої провайдера
     */
    public function send(OutgoingMessage $message): TransportReceipt;

    /** Ідентифікатор для журналу: «turbosms», «smtp», «null»… */
    public function name(): string;
}
