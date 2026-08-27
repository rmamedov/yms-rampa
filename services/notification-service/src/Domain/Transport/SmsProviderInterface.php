<?php

declare(strict_types=1);

namespace App\Domain\Transport;

/**
 * Адаптер SMS-провайдера (NOT-01).
 *
 * Вибір конкретного провайдера (TurboSMS, eSputnik) робиться env-конфігом
 * без зміни коду — усі вони реалізують цей інтерфейс.
 */
interface SmsProviderInterface
{
    /**
     * @return string ідентифікатор повідомлення у провайдера (для delivery-report)
     *
     * @throws TransportException при збої провайдера
     */
    public function send(string $phone, string $text): string;

    public function name(): string;
}
