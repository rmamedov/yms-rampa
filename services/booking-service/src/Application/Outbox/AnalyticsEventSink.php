<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use App\Domain\Exception\UpstreamUnavailableException;

/**
 * Приймач подій outbox поза межами booking-service.
 *
 * Порт існує рівно для того, щоб релей (OutboxRelay) не знав ані про HTTP,
 * ані про те, хто саме споживає події. Сьогодні за ним стоїть HTTP-виклик до
 * analytics-service; коли на сервері зʼявиться RabbitMQ, зміниться лише
 * реалізація порту — сам релей і команда лишаться без змін.
 */
interface AnalyticsEventSink
{
    /**
     * Доставити пакет подій. Успіх означає «сусід прийняв пакет цілком»
     * (структурно), а не «кожна подія змінила read-модель»: подробиці
     * повертаються в звіті.
     *
     * @param list<array<string, mixed>> $events конверти подій
     *
     * @throws UpstreamUnavailableException сусід недоступний або відповів
     *                                      не за контрактом — пакет НЕ доставлено
     */
    public function deliver(array $events): SinkReport;
}
